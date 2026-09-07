<?php

namespace App\Services\Prism;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PrismShipmentProcessor
{
    public function __construct(
        private readonly PrismClient $client,
        private readonly PrismOrderSnapshot $snapshots,
        private readonly PrismSkuResolver $skus,
        private readonly PrismCustomerSynchronizer $customers,
    ) {}

    public function process(int $id, bool $execute): array
    {
        $shipment = DB::table('prism_envios')->where('pe_id', $id)->first();
        if (! $shipment) {
            throw new RuntimeException('Envío no encontrado.');
        }
        $snapshot = $this->snapshots->load($shipment);
        if ($shipment->status === 'enviado') {
            return ['status' => 'ya_enviado', 'document_sid' => $shipment->document_sid];
        }
        if ($execute && (! config('storefront_post_purchase.integrations_enabled') || ! config('storefront_post_purchase.honduras.enabled'))) {
            throw new RuntimeException('Envío desactivado: ambos interruptores de integración externa deben estar en true.');
        }
        if (! Schema::hasColumn('prism_envios', 'processing_checkpoint')) {
            throw new RuntimeException('Falta aplicar el SQL/migración del procesador (processing_checkpoint y step VARCHAR).');
        }
        if ($execute && DB::connection()->getDriverName() === 'mysql' && Schema::getColumnType('prism_envios_log', 'step') === 'enum') {
            throw new RuntimeException('Falta convertir prism_envios_log.step a VARCHAR(80) antes de ejecutar.');
        }
        $journal = new PrismShipmentJournal($id, $shipment->processing_checkpoint);
        if ($execute) {
            $claimed = DB::table('prism_envios')->where('pe_id', $id)
                ->where('integration_environment', app()->environment())->whereIn('status', ['pendiente', 'error'])
                ->where('intentos', '<', max(1, (int) config('prism.hn.max_attempts')))
                ->update(['status' => 'procesando', 'intentos' => DB::raw('intentos + 1'), 'last_try_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                throw new RuntimeException('Envío ocupado, estado no elegible o límite de intentos alcanzado.');
            }
            $shipment = DB::table('prism_envios')->where('pe_id', $id)->first();
            $journal = new PrismShipmentJournal($id, $shipment->processing_checkpoint);
        }
        try {
            $snapshot = $this->snapshots->load($shipment);
            $this->validateInstallation();
            $this->customers->payload($snapshot); // Validate address before creating anything.
            $remoteStore = self::one($this->client->request('GET', '/v1/rest/store/'.$snapshot['store_sid'], ['cols' => '*']));
            if (self::sid($remoteStore) !== $snapshot['store_sid']
                || (string) ($remoteStore['store_code'] ?? '') !== $snapshot['store_code']
                || (int) ($remoteStore['store_number'] ?? -1) !== $snapshot['store_number']
                || (string) ($remoteStore['subsidiary_sid'] ?? '') !== (string) config('prism.hn.subsidiary_sid')
                || ! in_array($remoteStore['active'] ?? null, [true, 1, '1', 'true'], true)) {
                throw new RuntimeException('La tienda remota no coincide con el mapeo activo de Honduras.');
            }
            $items = $this->skus->resolve($snapshot['country_id'], $snapshot['store_code'], $snapshot['lines']);
            $this->customers->validateMatches($this->client->request('GET', '/v1/rest/customer/', [
                'cols' => '*', 'filter' => '(info1,eq,'.$snapshot['customer']['info1'].')',
            ]), $snapshot['customer']);
            usort($items, fn ($a, $b) => strcmp($a['sku'], $b['sku']));
            $fingerprint = hash('sha256', json_encode([$snapshot, $items, config('prism.hn.host'),
                config('prism.hn.subsidiary_sid'), config('prism.hn.tenant_sid')], JSON_THROW_ON_ERROR));
            if (isset($journal->checkpoint['fingerprint']) && $journal->checkpoint['fingerprint'] !== $fingerprint) {
                throw new RuntimeException('Pedido, artículos o instalación cambiaron después del primer intento; reconciliar antes de continuar.');
            }
            if (! $execute) {
                return ['status' => 'validado_sin_escrituras', 'shipment' => $id, 'reference' => $snapshot['reference'],
                    'order' => $shipment->ped_id, 'payment' => $shipment->ppa_id, 'type' => $snapshot['type'],
                    'store' => $snapshot['store_code'], 'items' => count($items),
                    'local_subtotal_reference' => $snapshot['total_cents'] === null ? null : $snapshot['total_cents'] / 100,
                    'prism_amount' => null,
                    'environment' => app()->environment()];
            }
            if ($shipment->document_sid && ! isset($journal->checkpoint['fingerprint'])) {
                throw new RuntimeException('Documento previo sin checkpoint del procesador: no se adopta automáticamente.');
            }
            $journal->checkpoint['fingerprint'] = $fingerprint;
            $journal->save();
            $document = $this->document($shipment, $snapshot, $journal);
            $sid = self::sid($document);
            $path = '/v1/rest/document/'.$sid;
            $finalized = (int) $document['status'] === 4;
            $this->items($path, $sid, $items, $journal, $finalized);
            $document = $this->readDocument($path, $journal);
            $prismTotal = $this->remoteTotal($document);
            if (isset($journal->checkpoint['prism_total_cents']) && $journal->checkpoint['prism_total_cents'] !== $prismTotal) {
                throw new RuntimeException('El total de Prism cambió desde el intento anterior; reconciliar antes de continuar.');
            }
            $journal->checkpoint['prism_total_cents'] = $prismTotal;
            $journal->save();
            $customerSid = $finalized ? (string) ($journal->checkpoint['customer_sid'] ?? '') : $this->customers->sync($snapshot, $journal, $this->client);
            if ($customerSid === '') {
                throw new RuntimeException('Documento finalizado sin cliente registrado en checkpoint.');
            }
            $document = $this->putDocument('document_bind_customer', $path, ['bt_cuid' => $customerSid], $journal);
            if ($snapshot['type'] === 'TARJETA') {
                if ($prismTotal <= 0) {
                    throw new RuntimeException('Documento de tarjeta sin total positivo para aplicar tender.');
                }
                $this->tender($path, $snapshot, $journal, $finalized, $prismTotal);
                $document = $this->putDocument('deposit_put', $path, ['so_deposit_amt_paid' => $prismTotal / 100], $journal);
            } else {
                $tenders = $journal->read($this->client, 'tender_get', $path.'/tender', ['cols' => '*']);
                if ($tenders !== []) {
                    throw new RuntimeException('Pedido en efectivo con tenders remotos inesperados.');
                }
                if (PrismOrderSnapshot::cents($document['so_deposit_amt_paid'] ?? 0) !== 0) {
                    throw new RuntimeException('Pedido en efectivo con depósito remoto inesperado.');
                }
            }
            // Revalidate local financial authority immediately before finalizing remotely.
            if ($this->snapshots->load($shipment) !== $snapshot) {
                throw new RuntimeException('El pedido cambió durante el procesamiento.');
            }
            $this->total($this->readDocument($path, $journal), $prismTotal);
            $document = $this->putDocument('document_hold', $path, ['status' => 4], $journal);
            $this->total($document, $prismTotal);
            if ((string) ($document['bt_cuid'] ?? '') !== $customerSid) {
                throw new RuntimeException('Documento final sin cliente esperado.');
            }
            if (isset($journal->checkpoint['uncertain'])) {
                throw new RuntimeException('Existen escrituras pendientes de reconciliación.');
            }
            $journal->log('done', 'VERIFIED');
            $journal->save(['status' => 'enviado', 'error_code' => null, 'error_message' => null,
                'document_sid' => $sid, 'document_row_version' => self::version($document)]);

            return ['status' => 'enviado', 'document_sid' => $sid, 'reference' => $snapshot['reference'], 'prism_amount' => $prismTotal / 100];
        } catch (\Throwable $exception) {
            if ($execute) {
                // Only our domain errors are safe to persist; SQL exceptions may contain PII.
                $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'Fallo interno del procesador; revisar configuración/esquema.';
                DB::table('prism_envios')->where('pe_id', $id)->where('status', 'procesando')->update([
                    'status' => 'error', 'error_code' => isset($journal->checkpoint['uncertain']) ? 'RECONCILE_REQUIRED' : 'PROCESS_FAILED',
                    'error_message' => $message, 'updated_at' => now(),
                ]);
            }
            throw $exception;
        } finally {
            try {
                $this->client->logout();
            } catch (\Throwable) {
                Log::warning('Prism: no se pudo cerrar la sesión del procesador.', ['shipment_id' => $id]);
            }
        }
    }

    private function validateInstallation(): void
    {
        foreach (['tenant_sid', 'subsidiary_sid', 'email_type_sid', 'address_type_sid', 'controller_sid'] as $key) {
            if (! preg_match('/^[1-9][0-9]*$/D', (string) config('prism.hn.'.$key))) {
                throw new RuntimeException('Configuración Prism inválida: '.$key);
            }
        }
    }

    private function document(object $shipment, array $snapshot, PrismShipmentJournal $journal): array
    {
        if ($shipment->document_sid) {
            $document = $this->readDocument('/v1/rest/document/'.$shipment->document_sid, $journal);
            if (self::sid($document) !== (string) $shipment->document_sid) {
                throw new RuntimeException('SID remoto no coincide con el documento guardado.');
            }
        } else {
            $found = $journal->read($this->client, 'document_find', '/v1/rest/document/', [
                'cols' => '*', 'filter' => '(order_tracking_number,eq,'.$snapshot['reference'].')',
            ]);
            if (! array_is_list($found) || count($found) > 1) {
                throw new RuntimeException('Referencia con múltiples documentos o respuesta inesperada.');
            }
            if ($found !== []) {
                if (($journal->checkpoint['uncertain'] ?? null) !== 'document_create') {
                    throw new RuntimeException('Ya existe documento remoto para la referencia: requiere conciliación manual.');
                }
                $document = self::one($found);
            } else {
                if (($journal->checkpoint['uncertain'] ?? null) === 'document_create') {
                    throw new RuntimeException('Creación de documento incierta y búsqueda vacía; no se repetirá el POST.');
                }
                $cashier = (string) config('prism.hn.cashier');
                $response = $journal->write($this->client, 'document_create', 'POST', '/v1/rest/document/', [[
                    'origin_application' => 'ECOMMERCE', 'use_vat' => true, 'was_audited' => false, 'is_held' => false,
                    'detax_flag' => false, 'archived' => 0, 'subsidiary_number' => (int) config('prism.hn.subsidiary_number'),
                    'store_number' => $snapshot['store_number'], 'btn_primary' => false, 'st_primary' => false,
                    'vat_options' => 0, 'cashier_login_name' => $cashier, 'cashier_full_name' => $cashier,
                    'employee1_login_name' => $cashier, 'employee1_full_name' => $cashier,
                    'created_datetime' => now()->format('Y-m-d\TH:i:s'), 'invoice_posted_date' => null,
                    'order_tracking_number' => $snapshot['reference'], 'comment1' => '', 'comment2' => '',
                    'cashier_name' => $cashier, 'tax_area_name' => 'IVA', 'status' => 3,
                ]]);
                $sid = self::sid(self::one($response));
                $journal->save(['document_sid' => $sid]);
                $document = $this->readDocument('/v1/rest/document/'.$sid, $journal);
            }
        }
        if ((string) ($document['order_tracking_number'] ?? '') !== $snapshot['reference']
            || (int) ($document['store_number'] ?? -1) !== $snapshot['store_number']
            || (int) ($document['subsidiary_number'] ?? -1) !== (int) config('prism.hn.subsidiary_number')
            || ! in_array((int) ($document['status'] ?? -1), [3, 4], true)) {
            throw new RuntimeException('Documento remoto no coincide con referencia, tienda, subsidiaria o estado esperado.');
        }
        $journal->verified('document_create', ['document_sid' => self::sid($document), 'document_row_version' => self::version($document)]);

        return $document;
    }

    private function items(string $path, string $documentSid, array $items, PrismShipmentJournal $journal, bool $finalized): void
    {
        $rows = $journal->read($this->client, 'items_get', $path.'/item', ['cols' => '*']);
        if ($rows === []) {
            if ($finalized) {
                throw new RuntimeException('Documento finalizado sin artículos; no se modificará.');
            }
            if (($journal->checkpoint['uncertain'] ?? null) === 'items_post' || isset($journal->checkpoint['completed']['items_post'])) {
                throw new RuntimeException('Artículos previamente enviados no visibles; reconciliar antes de repetir.');
            }
            $body = [];
            foreach ($items as $i => $item) {
                $row = ['item_pos' => $i + 1, 'document_sid' => $documentSid, 'origin_application' => 'ECOMMERCE',
                    'item_type' => 3, 'order_type' => 0, 'quantity' => $item['quantity'],
                    'manual_disc_value' => $item['discount'], 'invn_sbs_item_sid' => $item['sid']];
                if ($item['discount'] > 0) {
                    $row['manual_disc_type'] = 2;
                }
                $body[] = $row;
            }
            $journal->write($this->client, 'items_post', 'POST', $path.'/item', $body);
            $rows = $journal->read($this->client, 'items_verify', $path.'/item', ['cols' => '*']);
        }
        if (! array_is_list($rows) || count($rows) !== count($items)) {
            throw new RuntimeException('Artículos remotos parciales o duplicados: no se repetirá el POST.');
        }
        $expected = array_column($items, null, 'sid');
        foreach ($rows as $row) {
            $sid = (string) ($row['invn_sbs_item_sid'] ?? '');
            $item = $expected[$sid] ?? null;
            if (! $item || (float) ($row['quantity'] ?? -1) !== (float) $item['quantity']
                || (string) ($row['document_sid'] ?? '') !== $documentSid || (int) ($row['item_type'] ?? -1) !== 3
                || (int) ($row['order_type'] ?? -1) !== 0
                || (isset($row['manual_disc_value']) && $item['discount'] > 0 && (int) ($row['manual_disc_type'] ?? -1) !== 2)
                || $this->itemDiscountCents($row) !== PrismOrderSnapshot::cents($item['discount'])) {
                throw new RuntimeException('Artículos remotos no coinciden en SID/cantidad/descuento/tipo.');
            }
            unset($expected[$sid]);
        }
        $journal->verified('items_post');
    }

    private function tender(string $path, array $snapshot, PrismShipmentJournal $journal, bool $finalized, int $prismTotal): void
    {
        $rows = $journal->read($this->client, 'tender_get', $path.'/tender', ['cols' => '*']);
        if ($rows === []) {
            if ($finalized) {
                throw new RuntimeException('Documento finalizado sin tender; no se modificará.');
            }
            if (($journal->checkpoint['uncertain'] ?? null) === 'tender_post' || isset($journal->checkpoint['completed']['tender_post'])) {
                throw new RuntimeException('Tender incierto o previamente enviado no visible; no se repetirá.');
            }
            $journal->write($this->client, 'tender_post', 'POST', $path.'/tender', [[
                'origin_application' => 'ECOMMERCE', 'tender_type' => 0, 'taken' => $prismTotal / 100,
                'tender_name' => $snapshot['tender_name'], 'authorization_code' => $snapshot['authorization'],
                'card_type_name' => $snapshot['card_type'],
            ]]);
            $rows = $journal->read($this->client, 'tender_verify', $path.'/tender', ['cols' => '*']);
        }
        $tender = self::one($rows);
        if (PrismOrderSnapshot::cents($tender['taken'] ?? null) !== $prismTotal
            || (string) ($tender['authorization_code'] ?? '') !== $snapshot['authorization']
            || (string) ($tender['tender_name'] ?? '') !== $snapshot['tender_name']
            || (string) ($tender['card_type_name'] ?? '') !== $snapshot['card_type']
            || (int) ($tender['tender_type'] ?? -1) !== 0) {
            throw new RuntimeException('Tender remoto no coincide con importe/autorización/tipo del pago.');
        }
        $journal->verified('tender_post');
    }

    private function putDocument(string $step, string $path, array $desired, PrismShipmentJournal $journal): array
    {
        $document = $this->readDocument($path, $journal);
        $matches = function (array $document) use ($desired): bool {
            foreach ($desired as $key => $value) {
                if ($key === 'so_deposit_amt_paid') {
                    if (PrismOrderSnapshot::cents($document[$key] ?? 0) !== PrismOrderSnapshot::cents($value)) {
                        return false;
                    }
                } elseif ((string) ($document[$key] ?? '') !== (string) $value) {
                    return false;
                }
            }

            return true;
        };
        if (! $matches($document)) {
            if (($journal->checkpoint['uncertain'] ?? null) === $step) {
                throw new RuntimeException('PUT incierto no confirmado por GET: '.$step);
            }
            if ((int) ($document['status'] ?? -1) !== 3) {
                throw new RuntimeException('No se modificará un documento ya finalizado que difiere del pedido.');
            }
            $journal->write($this->client, $step, 'PUT', $path, [$desired], ['filter' => '(row_version,eq,'.self::version($document).')']);
            $document = $this->readDocument($path, $journal);
            if (! $matches($document)) {
                throw new RuntimeException('Prism no confirmó el PUT: '.$step);
            }
        }
        $journal->verified($step, ['document_row_version' => self::version($document)]);

        return $document;
    }

    private function readDocument(string $path, PrismShipmentJournal $journal): array
    {
        return self::one($journal->read($this->client, 'document_get', $path, ['cols' => '*']));
    }

    private function total(array $document, int $expected): void
    {
        if ($this->remoteTotal($document) !== $expected) {
            throw new RuntimeException('El total de Prism cambió durante el procesamiento; reconciliar antes de finalizar.');
        }
    }

    private function remoteTotal(array $document): int
    {
        $value = $document['transaction_total_amt'] ?? null;
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new RuntimeException('Prism: transaction_total_amt ausente o inválido.');
        }

        return PrismOrderSnapshot::cents($value);
    }

    private function itemDiscountCents(array $row): int
    {
        // Prism may clear the manual input fields after applying the discount.
        // Its persisted discount_amt is authoritative in that response format.
        $discount = $row['manual_disc_value'] ?? $row['discount_amt'] ?? null;
        if (! is_numeric($discount)) {
            throw new RuntimeException('Artículo Prism sin descuento numérico verificable (manual_disc_value/discount_amt).');
        }

        return PrismOrderSnapshot::cents($discount);
    }

    public static function one(array $rows): array
    {
        if (! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            throw new RuntimeException('Prism: se esperaba exactamente un registro JSON.');
        }

        return $rows[0];
    }

    public static function sid(array $row): string
    {
        $sid = (string) ($row['sid'] ?? '');
        if (! preg_match('/^[1-9][0-9]*$/D', $sid)) {
            throw new RuntimeException('Prism: SID ausente o inválido.');
        }

        return $sid;
    }

    public static function version(array $row): string
    {
        $version = (string) ($row['row_version'] ?? '');
        if (! ctype_digit($version)) {
            throw new RuntimeException('Prism: row_version ausente o inválido.');
        }

        return $version;
    }
}
