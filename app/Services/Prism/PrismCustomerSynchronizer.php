<?php

namespace App\Services\Prism;

use RuntimeException;

class PrismCustomerSynchronizer
{
    public function validateMatches(array $rows, array $input): void
    {
        if (! array_is_list($rows) || count($rows) > 1) {
            throw new RuntimeException('Cliente Prism ambiguo: se requiere coincidencia única por identificación.');
        }
        if ($rows !== []) {
            $customer = $rows[0];
            PrismShipmentProcessor::sid($customer);
            if ((string) ($customer['info1'] ?? '') !== $input['info1']
                || (string) ($customer['subsidiary_sid'] ?? '') !== (string) config('prism.hn.subsidiary_sid')
                || (string) ($customer['tenant_sid'] ?? '') !== (string) config('prism.hn.tenant_sid')) {
                throw new RuntimeException('Cliente Prism no coincide con identificación, tenant o subsidiaria.');
            }
        }
    }

    public function sync(array $snapshot, PrismShipmentJournal $journal, PrismClient $client): string
    {
        $input = $snapshot['customer'];
        $rows = $journal->read($client, 'customer_find', '/v1/rest/customer/', [
            'cols' => '*', 'filter' => '(info1,eq,'.$input['info1'].')',
        ]);
        $this->validateMatches($rows, $input);
        $uncertain = $journal->checkpoint['uncertain'] ?? null;
        if ($rows === []) {
            if ($uncertain === 'customer_create' || isset($journal->checkpoint['customer_sid'])) {
                throw new RuntimeException('Cliente previamente creado o incierto no encontrado; reconciliar sin repetir POST.');
            }
            $payload = $this->payload($snapshot);
            $response = $journal->write($client, 'customer_create', 'POST', '/v1/rest/customer', [$payload]);
            $customer = PrismShipmentProcessor::one($response);
            $sid = PrismShipmentProcessor::sid($customer);
            $journal->checkpoint['customer_sid'] = $sid;
            $journal->save(['customer_sid' => $sid]);
            $customer = PrismShipmentProcessor::one($journal->read($client, 'customer_verify', '/v1/rest/customer/'.$sid, ['cols' => '*']));
        } else {
            $customer = $rows[0];
        }
        $sid = PrismShipmentProcessor::sid($customer);
        if ((string) ($customer['info1'] ?? '') !== $input['info1']
            || (string) ($customer['subsidiary_sid'] ?? '') !== (string) config('prism.hn.subsidiary_sid')
            || (string) ($customer['tenant_sid'] ?? '') !== (string) config('prism.hn.tenant_sid')
            || (isset($journal->checkpoint['customer_sid']) && $journal->checkpoint['customer_sid'] !== $sid)) {
            throw new RuntimeException('Cliente Prism no coincide con identificación, tenant o subsidiaria.');
        }
        if ($uncertain === 'customer_create' && ($customer['notes'] ?? '') !== 'Cliente creado desde API (Pedido: '.$snapshot['reference'].')') {
            throw new RuntimeException('Alta de cliente incierta: la coincidencia no identifica nuestra creación.');
        }
        $journal->checkpoint['customer_sid'] = $sid;
        $journal->verified('customer_create', ['customer_sid' => $sid, 'customer_row_version' => (string) ($customer['row_version'] ?? '')]);

        $parts = self::address($input['address']);
        $updates = [
            'emails' => ['email_address' => $input['email_address']],
            'addresses' => ['address_line_1' => $parts[0], 'address_line_2' => $parts[1]],
            'phones' => ['phone_no' => $input['phone']],
        ];
        foreach ($updates as $collection => $desired) {
            if (($collection === 'addresses' && $input['address'] === '') || ($collection === 'phones' && $input['phone'] === '')) {
                continue;
            }
            $links = $customer[$collection] ?? [];
            if (! is_array($links) || $links === []) {
                // Legacy did not create missing contact records on existing customers.
                $journal->log('customer_'.$collection, 'SKIP_NO_CONTACT_LINK');

                continue;
            }
            $contacts = [];
            $singular = ['emails' => 'email', 'addresses' => 'address', 'phones' => 'phone'][$collection];
            foreach ($links as $link) {
                $path = $link['link'] ?? '';
                if (! preg_match('#^/v1/rest/customer/'.preg_quote($sid, '#').'/'.$singular.'/[0-9]+$#D', $path)) {
                    throw new RuntimeException('Link de contacto Prism no pertenece al cliente.');
                }
                $contacts[] = ['path' => $path, 'data' => PrismShipmentProcessor::one($journal->read($client, 'customer_'.$collection.'_get', $path, ['cols' => '*']))];
            }
            $selected = $contacts[0];
            foreach ($contacts as $contact) {
                if (in_array($contact['data']['primary_flag'] ?? null, [true, 1, '1', 'true'], true)) {
                    $selected = $contact;
                    break;
                }
            }
            $step = 'customer_'.$collection.'_put';
            $matches = $this->matches($selected['data'], $desired);
            if (! $matches) {
                if (($journal->checkpoint['uncertain'] ?? null) === $step) {
                    throw new RuntimeException('Actualización de contacto incierta; el GET no confirma el resultado.');
                }
                $journal->write($client, $step, 'PUT', $selected['path'], [$desired], [
                    'filter' => '(row_version,eq,'.PrismShipmentProcessor::version($selected['data']).')',
                ]);
                $updated = PrismShipmentProcessor::one($journal->read($client, $step.'_verify', $selected['path'], ['cols' => '*']));
                if (! $this->matches($updated, $desired)) {
                    throw new RuntimeException('Prism no confirmó la actualización del contacto.');
                }
            }
            $journal->verified($step);
        }

        return $sid;
    }

    private function matches(array $record, array $desired): bool
    {
        foreach ($desired as $key => $value) {
            if ((string) ($record[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    public function payload(array $snapshot): array
    {
        $input = $snapshot['customer'];
        $parts = self::address($input['address']);

        return ['tenant_sid' => (string) config('prism.hn.tenant_sid'),
            'subsidiary_sid' => (string) config('prism.hn.subsidiary_sid'), 'store_sid' => $snapshot['store_sid'],
            'customer_active' => true, 'customer_type' => 1, 'share_type' => 1,
            'first_name' => $input['first_name'], 'last_name' => $input['last_name'],
            'email_address' => $input['email_address'], 'info1' => $input['info1'],
            'notes' => 'Cliente creado desde API (Pedido: '.$snapshot['reference'].')',
            'allowed_tenders' => 'TTTTTTTTTTTTTTTTTTTT', 'origin_application' => 'API',
            'addresses' => [['address_line_1' => $parts[0], 'address_line_2' => $parts[1],
                'primary_flag' => true, 'active' => true, 'address_type_sid' => (string) config('prism.hn.address_type_sid')]],
            'phones' => [['phone_no' => $input['phone'], 'primary_flag' => true, 'phone_type' => 'Home']],
            'emails' => [['first_name' => $input['first_name'], 'last_name' => $input['last_name'],
                'origin_application' => 'API', 'email_type_sid' => (string) config('prism.hn.email_type_sid'),
                'controller_sid' => (string) config('prism.hn.controller_sid'), 'email_address' => $input['email_address'],
                'email_type' => 'Home', 'primary_flag' => true]]];
    }

    public static function address(string $address): array
    {
        $address = trim(preg_replace('/\s+/', ' ', $address));
        // Preserve legacy's two 40-character fields, but don't silently lose data.
        if (mb_strlen($address) > 80) {
            throw new RuntimeException('Dirección excede dos líneas de 40 caracteres de Prism; revisar antes de enviar.');
        }

        return [mb_substr($address, 0, 40), mb_substr($address, 40, 40)];
    }
}
