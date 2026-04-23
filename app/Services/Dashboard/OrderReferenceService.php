<?php

namespace App\Services\Dashboard;

use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderReferenceService
{
    public function __construct(
        private readonly Smtp2GoMailer $mailer,
    ) {
    }

    public function show(string $reference, string $country): array
    {
        $countryId = $this->resolveCountryId($country);
        $order = $this->order($reference, $countryId);

        if (! $order) {
            throw ValidationException::withMessages([
                'reference' => 'No se encontro la referencia indicada.',
            ]);
        }

        $products = $this->withLoggedChanges(
            (string) $order->ppa_ref,
            $this->products((string) $order->ppa_ref, $countryId),
        );

        return [
            'order' => $this->normalizeOrder($order, $products),
            'products' => $products,
        ];
    }

    public function lookupProduct(string $sku, string $country, ?string $size = null): array
    {
        $countryId = $this->resolveCountryId($country);
        $product = $this->resolveActiveProduct($sku, $countryId);

        if (filled($size)) {
            $this->ensureValidSize($product, (string) $size);
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLine(int $lineId, array $data, array $actor = []): array
    {
        return DB::transaction(function () use ($lineId, $data, $actor) {
            $line = $this->editableLine($lineId);

            if (! $line) {
                throw ValidationException::withMessages([
                    'line' => 'La linea seleccionada no existe.',
                ]);
            }

            if ((string) $line->ped_estatus !== 'RECIBIDO') {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden editar articulos de pedidos en estado RECIBIDO.',
                ]);
            }

            $countryId = (int) $line->ped_id_pais;
            $product = $this->resolveActiveProduct((string) $data['sku'], $countryId);
            $this->ensureValidSize($product, (string) $data['size']);

            $quantity = max(0, (int) $data['quantity']);
            $discount = max(0, min(100, (float) ($data['discount'] ?? 0)));
            $actorName = $this->actorName($actor);

            DB::table('stj_pedidos_detalle')
                ->where('car_id', $lineId)
                ->update([
                    'car_producto' => $product['id'],
                    'car_precio' => $product['price'],
                    'car_talla' => trim((string) $data['size']),
                    'car_cantidad' => $quantity,
                    'car_descuento' => $discount,
                    'car_total_facturado' => null,
                    'car_descuento_final' => null,
                    'car_estilo_final' => null,
                    'car_talla_final' => null,
                    'car_modificar' => 'SI',
                    'car_a_usuario' => $actorName,
                    'car_a_ip' => $actor['ip'] ?? Request::ip(),
                    'car_a_fecha' => now(),
                ]);

            $updatedLine = $this->editableLine($lineId);

            if ($updatedLine) {
                $this->logLineChange($line, $updatedLine, $actor);
            }

            return $this->show((string) $line->car_ref, (string) $countryId);
        });
    }

    /**
     * @param array<string, mixed> $actor
     */
    public function processOrder(string $reference, string $country, string $ticket, array $actor = []): array
    {
        $processed = DB::transaction(function () use ($reference, $country, $ticket, $actor) {
            $countryId = $this->resolveCountryId($country);
            $order = $this->orderForProcessing($reference, $countryId);

            if (! $order) {
                throw ValidationException::withMessages([
                    'reference' => 'No se encontro la referencia indicada.',
                ]);
            }

            if ((string) $order->ped_estatus !== 'RECIBIDO') {
                throw ValidationException::withMessages([
                    'order' => 'Solo se pueden procesar pedidos en estado RECIBIDO.',
                ]);
            }

            $products = $this->products((string) $order->ppa_ref, $countryId);

            if ($products === []) {
                throw ValidationException::withMessages([
                    'products' => 'El pedido no tiene articulos para procesar.',
                ]);
            }

            $now = now();
            $actorName = $this->actorName($actor);
            $shipping = (string) ($order->ped_checkout ?? '') === 'DOMICILIO'
                ? (float) ($order->pdi_costo_envio_final ?? 0)
                : 0.0;
            $items = collect($products)->sum(fn (array $product) => (int) ($product['quantity'] ?? 0));
            $chargedSubtotal = collect($products)->sum(fn (array $product) => (float) ($product['chargedSubtotal'] ?? 0));
            $orderIsCancelledByInventory = $items === 0;
            $calculatedPaid = $orderIsCancelledByInventory ? 0.0 : round($chargedSubtotal + $shipping, 2);
            $originalPaid = round((float) ($order->ppa_monto ?? 0), 2);
            $difference = round($calculatedPaid - $originalPaid, 2);
            $isCardPayment = strtoupper((string) ($order->ppa_tipo ?? '')) === 'TARJETA';
            $refund = $isCardPayment && $difference < 0 ? abs($difference) : 0.0;
            $originalItems = (int) ($order->ppa_articulos ?? 0);
            $originalProducts = round((float) ($order->ppa_monto_senv ?? 0), 2);
            $hasLineChanges = $this->hasLineChanges((string) $order->ppa_ref);
            $orderStatus = $orderIsCancelledByInventory ? 'ANULADO-INVENTARIO' : 'PREPARADO';
            $productStatus = $orderIsCancelledByInventory
                ? 'SIN-EXISTENCIAS'
                : (($items !== $originalItems || $hasLineChanges || abs(round($chargedSubtotal - $originalProducts, 2)) >= 0.01)
                    ? 'INCOMPLETO'
                    : 'COMPLETO');

            DB::table('stj_pedidos')
                ->where('ped_id', (int) $order->ped_id)
                ->update([
                    'ped_estatus' => $orderStatus,
                    'ped_estatus_productos' => $productStatus,
                    'ped_devolucion_realizada' => $refund > 0 ? 'NO' : 'N/A',
                    'ped_monto_devolucion' => $refund > 0 ? $refund : null,
                    'ped_fecha_devolucion' => $refund > 0 ? $now : null,
                    'ped_fecha_devolucion_sistema' => $refund > 0 ? $now : null,
                    'ped_a_usuario' => $actorName,
                    'ped_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ped_a_fecha' => $now,
                ]);

            DB::table('stj_pedidos_pago')
                ->where('ppa_id', (int) $order->ppa_id)
                ->update([
                    'ppa_ticket' => trim($ticket),
                    'ppa_fecha_procesado' => $now,
                    'ppa_a_usuario' => $actorName,
                    'ppa_a_ip' => $actor['ip'] ?? Request::ip(),
                    'ppa_a_fecha' => $now,
                ]);

            $details = DB::table('stj_pedidos_detalle as detail')
                ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
                ->where('detail.car_ref', (string) $order->ppa_ref)
                ->where('detail.car_pais', $countryId)
                ->where('detail.car_accion', 'AGREGADO')
                ->select([
                    'detail.car_id',
                    'detail.car_cantidad',
                    'detail.car_descuento',
                    'detail.car_talla',
                    'product.pro_codigo',
                ])
                ->get();

            foreach ($details as $detail) {
                DB::table('stj_pedidos_detalle')
                    ->where('car_id', (int) $detail->car_id)
                    ->update([
                        'car_total_facturado' => (int) $detail->car_cantidad,
                        'car_descuento_final' => (float) ($detail->car_descuento ?? 0),
                        'car_estilo_final' => (string) $detail->pro_codigo,
                        'car_talla_final' => (string) ($detail->car_talla ?? ''),
                        'car_a_usuario' => $actorName,
                        'car_a_ip' => $actor['ip'] ?? Request::ip(),
                        'car_a_fecha' => $now,
                    ]);
            }

            return $this->show((string) $order->ppa_ref, (string) $countryId);
        });

        $processed['mail'] = $this->sendProcessedOrderEmail($processed);

        return $processed;
    }

    private function editableLine(int $lineId): ?object
    {
        return DB::table('stj_pedidos_detalle as detail')
            ->join('stj_pedidos_pago as pay', function ($join) {
                $join->on('pay.ppa_ref', '=', 'detail.car_ref')
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->join('stj_pedidos as order', 'order.ped_id', '=', 'pay.ppa_pedido')
            ->leftJoin('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->selectRaw('
                detail.*,
                pay.ppa_id,
                order.ped_id,
                order.ped_id_pais,
                order.ped_estatus,
                product.pro_codigo,
                product.pro_nombre
            ')
            ->where('detail.car_id', $lineId)
            ->where('detail.car_accion', 'AGREGADO')
            ->first();
    }

    private function orderForProcessing(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_direccion as pd', 'p.ped_id', '=', 'pd.pdi_pedido')
            ->join('stj_pedidos_pago as pay', function ($join) use ($reference) {
                $join->on('pay.ppa_pedido', '=', 'p.ped_id')
                    ->where('pay.ppa_ref', '=', $reference)
                    ->where('pay.ppa_estado', '=', 'APROBADA');
            })
            ->where('p.ped_id_pais', $countryId)
            ->select([
                'p.ped_id',
                'p.ped_id_pais',
                'p.ped_estatus',
                'p.ped_checkout',
                'pd.pdi_costo_envio_final',
                'pay.ppa_id',
                'pay.ppa_ref',
                'pay.ppa_monto',
                'pay.ppa_monto_senv',
                'pay.ppa_articulos',
                'pay.ppa_tipo',
            ])
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $processed
     * @return array{sent: bool, skipped: bool, reason: string|null}
     */
    private function sendProcessedOrderEmail(array $processed): array
    {
        $order = $processed['order'];
        $email = trim((string) data_get($order, 'customer.email'));

        if ($email === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Pedido sin correo de cliente.',
            ];
        }

        if ($this->isBouncedEmail($email)) {
            return [
                'sent' => false,
                'skipped' => true,
                'reason' => 'Correo en lista de rebotes.',
            ];
        }

        try {
            $message = $this->processedOrderMail($processed);

            $this->mailer->sendHtml(
                to: $email,
                subject: $message['subject'],
                html: $message['html'],
            );

            return [
                'sent' => true,
                'skipped' => false,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('No fue posible enviar correo de pedido procesado.', [
                'reference' => data_get($order, 'reference'),
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'skipped' => false,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    private function isBouncedEmail(string $email): bool
    {
        return DB::table('correos_rebotados')
            ->where('correo', $email)
            ->exists();
    }

    private function hasLineChanges(string $reference): bool
    {
        return DB::table('stj_pedidos_detalle_log')
            ->where('pdl_ref', $reference)
            ->exists();
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private function withLoggedChanges(string $reference, array $products): array
    {
        $logs = DB::table('stj_pedidos_detalle_log')
            ->where('pdl_ref', $reference)
            ->orderBy('pdl_id')
            ->get()
            ->groupBy('pdl_detalle_id');

        if ($logs->isEmpty()) {
            return $products;
        }

        return collect($products)
            ->map(function (array $product) use ($logs): array {
                $lineLogs = $logs->get($product['id']);

                if (! $lineLogs || $lineLogs->isEmpty()) {
                    return $product;
                }

                $first = $lineLogs->first();
                $last = $lineLogs->last();
                $productChanged = (string) ($first->pdl_codigo_anterior ?? '') !== (string) ($last->pdl_codigo_nuevo ?? '')
                    || (string) ($first->pdl_talla_anterior ?? '') !== (string) ($last->pdl_talla_nueva ?? '');
                $quantityChanged = (int) ($first->pdl_cantidad_anterior ?? 0) !== (int) ($last->pdl_cantidad_nueva ?? 0);

                $product['loggedChange'] = [
                    'changed' => $productChanged || $quantityChanged,
                    'productChanged' => $productChanged,
                    'quantityChanged' => $quantityChanged,
                    'sku' => (string) ($first->pdl_codigo_anterior ?? ''),
                    'name' => (string) ($first->pdl_nombre_anterior ?? ''),
                    'size' => (string) ($first->pdl_talla_anterior ?? ''),
                    'quantity' => (int) ($first->pdl_cantidad_anterior ?? 0),
                    'newSku' => (string) ($last->pdl_codigo_nuevo ?? ''),
                    'newName' => (string) ($last->pdl_nombre_nuevo ?? ''),
                    'newSize' => (string) ($last->pdl_talla_nueva ?? ''),
                    'newQuantity' => (int) ($last->pdl_cantidad_nueva ?? 0),
                ];

                return $product;
            })
            ->values()
            ->all();
    }

    /**
     * @param array{order: array<string, mixed>, products: array<int, array<string, mixed>>} $processed
     * @return array{subject: string, html: string}
     */
    private function processedOrderMail(array $processed): array
    {
        $order = $processed['order'];
        $products = $this->withLoggedChanges((string) data_get($order, 'reference'), $processed['products']);
        $reference = (string) data_get($order, 'reference');
        $checkout = (string) data_get($order, 'checkout');
        $status = (string) data_get($order, 'status');
        $customer = e((string) data_get($order, 'customer.name', 'cliente'));
        $countryId = (int) data_get($order, 'countryId');
        $currency = $this->currency($countryId);
        $refund = (float) data_get($order, 'totals.refund', 0);
        $subject = $status === 'ANULADO-INVENTARIO'
            ? "Pedido #{$reference} no disponible"
            : ($checkout === 'DOMICILIO'
            ? "Pedido #{$reference} en ruta"
            : "Pedido #{$reference} preparado");
        $title = $status === 'ANULADO-INVENTARIO'
            ? 'Pedido no disponible'
            : ($checkout === 'DOMICILIO' ? 'Pedido en ruta' : 'Pedido preparado');
        $intro = $status === 'ANULADO-INVENTARIO'
            ? "No podemos facturar tu pedido con numero de referencia {$reference} debido a disponibilidad de inventario."
            : ($checkout === 'DOMICILIO'
            ? "Tu pedido con numero de referencia {$reference} se encuentra en ruta."
            : "Tu pedido con numero de referencia {$reference} esta listo para retirarlo.");

        $deliveryRows = $checkout === 'DOMICILIO'
            ? [
                'Tipo de entrega' => 'Domicilio',
                'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
                'Costo de envio' => $currency.' '.number_format((float) data_get($order, 'shipping.cost', 0), 2),
                'Direccion' => (string) data_get($order, 'shipping.address', ''),
            ]
            : [
                'Tipo de entrega' => trim('Retiro en tienda '.(string) data_get($order, 'storePickup.storeName', '')),
                'Fecha de compra' => $this->mailDate((string) data_get($order, 'createdAt')),
                'Total de productos' => (string) data_get($order, 'totals.itemsBilled', data_get($order, 'totals.items')),
            ];

        $changeNotice = $this->mailChangeNotice($order, $products, $currency, $refund);
        $tracking = $checkout === 'DOMICILIO'
            ? '<p>Puedes rastrear tu orden en <a href="https://stjacks.com/'.$this->countrySlug($countryId).'/Productos/Ordenes">stjacks.com</a> ingresando el numero de referencia.</p>'
            : '';

        $html = '<!doctype html>
            <html>
            <body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#1f2937;">
                <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fb;padding:24px 0;">
                    <tr>
                        <td align="center">
                            <table width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border:1px solid #e5e7eb;">
                                <tr>
                                    <td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;">
                                        <h1 style="margin:0;font-size:24px;color:#111827;">'.$title.'</h1>
                                        <p style="margin:12px 0 0;font-size:15px;">Hola <strong>'.$customer.'</strong>,</p>
                                        <p style="margin:8px 0 0;font-size:15px;">'.e($intro).'</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:22px 28px;">
                                        '.$this->mailKeyValueTable($deliveryRows).'
                                        '.$changeNotice.'
                                        '.$tracking.'
                                        <p style="margin-top:24px;font-size:13px;color:#6b7280;">Gracias por comprar en St. Jack\'s Online.</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>';

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $products
     */
    private function mailChangeNotice(array $order, array $products, string $currency, float $refund): string
    {
        $itemsOriginal = (int) data_get($order, 'totals.itemsOriginal', 0);
        $itemsBilled = (int) data_get($order, 'totals.itemsBilled', 0);
        $hasChangedProducts = collect($products)->contains(function (array $product): bool {
            return (int) ($product['quantity'] ?? 0) !== (int) ($product['billedQuantity'] ?? 0)
                || (bool) data_get($product, 'substitute.hasSubstitute', false)
                || (bool) data_get($product, 'loggedChange.changed', false);
        });

        $notice = '';

        if ($itemsBilled === 0) {
            $notice .= '<p style="margin-top:18px;">Lamentamos informarle que no podemos facturar su pedido debido a disponibilidad de inventario.</p>';
        } elseif ($itemsOriginal !== $itemsBilled || $hasChangedProducts) {
            $notice .= '<p style="margin-top:18px;">Lamentamos informarle que sus articulos sufrieron cambios debido a disponibilidad de inventario.</p>';
        }

        $notice .= $this->mailProductsTable($products, $hasChangedProducts || $itemsOriginal !== $itemsBilled);

        if ($refund > 0) {
            $notice .= '<p style="margin-top:18px;"><strong>Monto devolucion:</strong> '.$currency.' '.number_format($refund, 2).'</p>';
            $notice .= '<p>Fondos liberados; proceso de devolucion iniciado, en un plazo maximo de 7 dias habiles, el banco emisor de su tarjeta hara efectivo el reintegro de su dinero.</p>';
        }

        return $notice;
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function mailProductsTable(array $products, bool $showBilledQuantity): string
    {
        $headers = $showBilledQuantity
            ? ['Sustituido', 'SKU', 'Descripcion', 'Talla', 'Cantidad solicitada', 'Cantidad facturada']
            : ['Sustituido', 'SKU', 'Descripcion', 'Talla', 'Cantidad'];
        $rows = '';

        foreach ($products as $product) {
            $hasSubstitute = (bool) data_get($product, 'substitute.hasSubstitute', false);
            $hasLoggedProductChange = (bool) data_get($product, 'loggedChange.productChanged', false);
            $hasLoggedQuantityChange = (bool) data_get($product, 'loggedChange.quantityChanged', false);
            $hasChange = $hasSubstitute || $hasLoggedProductChange;
            $strike = $hasChange ? 'text-decoration:line-through;' : '';
            $sku = '<span style="'.$strike.'">'.e($hasLoggedProductChange ? (string) data_get($product, 'loggedChange.sku') : (string) ($product['sku'] ?? '')).'</span>';
            $name = '<span style="'.$strike.'">'.e($hasLoggedProductChange ? (string) data_get($product, 'loggedChange.name') : (string) ($product['name'] ?? '')).'</span>';
            $size = '<span style="'.$strike.'">'.e($hasLoggedProductChange ? (string) data_get($product, 'loggedChange.size') : (string) ($product['size'] ?? '')).'</span>';
            $quantity = $hasLoggedQuantityChange ? (int) data_get($product, 'loggedChange.quantity', 0) : (int) ($product['quantity'] ?? 0);

            if ($hasLoggedProductChange) {
                $sku .= '<br>'.e((string) data_get($product, 'loggedChange.newSku'));
                $name .= '<br>'.e((string) data_get($product, 'loggedChange.newName'));
                $size .= '<br>'.e((string) data_get($product, 'loggedChange.newSize'));
            } elseif ($hasSubstitute) {
                $sku .= '<br>'.e((string) data_get($product, 'substitute.sku'));
                $name .= '<br>'.e((string) data_get($product, 'substitute.name'));
                $size .= '<br>'.e((string) data_get($product, 'substitute.size'));
            }

            $rows .= '<tr>
                <td style="border:1px solid #d1d5db;padding:7px;">'.($hasChange ? 'SI' : 'NO').'</td>
                <td style="border:1px solid #d1d5db;padding:7px;">'.$sku.'</td>
                <td style="border:1px solid #d1d5db;padding:7px;">'.$name.'</td>
                <td style="border:1px solid #d1d5db;padding:7px;">'.$size.'</td>
                <td style="border:1px solid #d1d5db;padding:7px;text-align:right;">'.number_format($quantity).'</td>';

            if ($showBilledQuantity) {
                $rows .= '<td style="border:1px solid #d1d5db;padding:7px;text-align:right;">'.number_format((int) ($product['billedQuantity'] ?? 0)).'</td>';
            }

            $rows .= '</tr>';
        }

        return '<table cellpadding="0" cellspacing="0" style="margin-top:18px;border-collapse:collapse;width:100%;font-size:13px;">
            <thead><tr>'.collect($headers)->map(fn (string $header) => '<th style="border:1px solid #d1d5db;padding:7px;background:#f3f4f6;text-align:left;">'.$header.'</th>')->implode('').'</tr></thead>
            <tbody>'.$rows.'</tbody>
        </table>';
    }

    /**
     * @param array<string, string> $rows
     */
    private function mailKeyValueTable(array $rows): string
    {
        return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px;">'
            .collect($rows)
                ->filter(fn (string $value) => trim($value) !== '')
                ->map(fn (string $value, string $label) => '<tr>
                    <td style="border:1px solid #d1d5db;padding:8px;font-weight:bold;width:38%;">'.e($label).'</td>
                    <td style="border:1px solid #d1d5db;padding:8px;">'.e($value).'</td>
                </tr>')
                ->implode('')
            .'</table>';
    }

    private function mailDate(string $value): string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return 'N/D';
        }

        return $value;
    }

    private function currency(int $countryId): string
    {
        return match ($countryId) {
            2 => 'Q',
            3 => 'CRC',
            default => 'USD',
        };
    }

    private function countrySlug(int $countryId): string
    {
        return match ($countryId) {
            2 => 'Guatemala',
            3 => 'CostaRica',
            5 => 'Panama',
            default => 'ElSalvador',
        };
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function logLineChange(object $previous, object $updated, array $actor): void
    {
        DB::table('stj_pedidos_detalle_log')->insert([
            'pdl_pedido_id' => (int) $previous->ped_id,
            'pdl_pago_id' => (int) $previous->ppa_id,
            'pdl_detalle_id' => (int) $previous->car_id,
            'pdl_ref' => (string) $previous->car_ref,
            'pdl_pais' => (int) $previous->ped_id_pais,
            'pdl_usuario_id' => $actor['id'] ?? null,
            'pdl_usuario_nombre' => $actor['name'] ?? $actor['username'] ?? null,
            'pdl_usuario_correo' => $actor['email'] ?? null,
            'pdl_usuario_operaciones' => $this->json($actor['permissions'] ?? []),
            'pdl_ip' => $actor['ip'] ?? Request::ip(),
            'pdl_user_agent' => $actor['userAgent'] ?? request()->userAgent(),
            'pdl_origen' => 'stj-dashboard',
            'pdl_producto_id_anterior' => (int) $previous->car_producto,
            'pdl_codigo_anterior' => (string) $previous->pro_codigo,
            'pdl_nombre_anterior' => (string) $previous->pro_nombre,
            'pdl_talla_anterior' => (string) ($previous->car_talla ?? ''),
            'pdl_cantidad_anterior' => (int) ($previous->car_cantidad ?? 0),
            'pdl_precio_anterior' => (float) ($previous->car_precio ?? 0),
            'pdl_descuento_anterior' => (float) ($previous->car_descuento ?? 0),
            'pdl_producto_id_nuevo' => (int) $updated->car_producto,
            'pdl_codigo_nuevo' => (string) $updated->pro_codigo,
            'pdl_nombre_nuevo' => (string) $updated->pro_nombre,
            'pdl_talla_nueva' => (string) ($updated->car_talla ?? ''),
            'pdl_cantidad_nueva' => (int) ($updated->car_cantidad ?? 0),
            'pdl_precio_nuevo' => (float) ($updated->car_precio ?? 0),
            'pdl_descuento_nuevo' => (float) ($updated->car_descuento ?? 0),
            'pdl_total_facturado_anterior' => $previous->car_total_facturado,
            'pdl_total_facturado_nuevo' => $updated->car_total_facturado,
            'pdl_estilo_final_anterior' => $previous->car_estilo_final,
            'pdl_estilo_final_nuevo' => $updated->car_estilo_final,
            'pdl_talla_final_anterior' => $previous->car_talla_final,
            'pdl_talla_final_nueva' => $updated->car_talla_final,
            'pdl_descuento_final_anterior' => $previous->car_descuento_final,
            'pdl_descuento_final_nuevo' => $updated->car_descuento_final,
            'pdl_snapshot_anterior' => $this->json($previous),
            'pdl_snapshot_nuevo' => $this->json($updated),
            'pdl_motivo' => 'Edicion manual de detalle de pedido',
            'pdl_fecha' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function actorName(array $actor): string
    {
        return (string) ($actor['username'] ?? $actor['email'] ?? $actor['name'] ?? 'stj-dashboard');
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function order(string $reference, int $countryId): ?object
    {
        return DB::table('stj_pedidos as p')
            ->leftJoin('stj_pedidos_direccion as pd', 'p.ped_id', '=', 'pd.pdi_pedido')
            ->leftJoin('stj_direcciones as d', 'pd.pdi_direccion', '=', 'd.dir_id')
            ->leftJoin('stj_pedidos_tienda as pt', 'p.ped_id', '=', 'pt.pti_pedido')
            ->leftJoin('stj_tiendas as t', function ($join) use ($countryId) {
                $join->on('pt.pti_tienda', '=', 't.tie_codigo')
                    ->orOn('p.ped_tienda', '=', 't.tie_codigo')
                    ->where('t.tie_pais', '=', $countryId);
            })
            ->leftJoin('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->leftJoin('stj_mensajes_fac as mf', function ($join) {
                $join->on('mf.mfa_tarjeta', '=', 'pay.ppa_emisor')
                    ->on('mf.mfa_codigo', '=', 'pay.ppa_rsp_codigo');
            })
            ->where('pay.ppa_ref', $reference)
            ->where('pay.ppa_estado', 'APROBADA')
            ->where('p.ped_id_pais', $countryId)
            ->select([
                'p.*',
                'pd.*',
                'd.*',
                'pt.*',
                't.*',
                'pay.*',
                'mf.*',
            ])
            ->first();
    }

    private function products(string $reference, int $countryId): array
    {
        return DB::table('stj_pedidos_detalle as detail')
            ->join('stj_productos as product', 'product.pro_id', '=', 'detail.car_producto')
            ->join('stj_producto_pais as country_product', function ($join) use ($countryId) {
                $join->on('country_product.ppa_producto', '=', 'product.pro_id')
                    ->where('country_product.ppa_pais', '=', $countryId);
            })
            ->where('detail.car_ref', $reference)
            ->where('detail.car_accion', 'AGREGADO')
            ->where('detail.car_pais', $countryId)
            ->selectRaw("
                detail.*,
                product.pro_codigo,
                product.pro_nombre,
                country_product.ppa_precio,
                (SELECT sp.pro_nombre FROM stj_productos sp WHERE sp.pro_codigo = detail.car_estilo_final LIMIT 1) AS estilo_final_nombre
            ")
            ->get()
            ->map(fn ($product) => $this->normalizeProduct($product))
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function normalizeOrder(object $order, array $products): array
    {
        $shipping = (float) ($order->pdi_costo_envio_final ?? 0);
        $chargedSubtotal = collect($products)->sum(fn (array $product) => (float) ($product['chargedSubtotal'] ?? 0));
        $billedSubtotal = collect($products)->sum(fn (array $product) => (float) ($product['billedSubtotal'] ?? 0));
        $items = collect($products)->sum(fn (array $product) => (int) ($product['quantity'] ?? 0));
        $itemsBilled = collect($products)->sum(fn (array $product) => (int) ($product['billedQuantity'] ?? 0));
        $productsTotal = round($chargedSubtotal, 2);
        $productsOriginal = round((float) ($order->ppa_monto_senv ?? 0), 2);
        $paid = round((float) ($order->ppa_monto ?? 0), 2);
        $paidCalculated = round($chargedSubtotal + ((string) ($order->ped_checkout ?? '') === 'DOMICILIO' ? $shipping : 0), 2);

        return [
            'id' => (int) $order->ped_id,
            'paymentId' => (int) $order->ppa_id,
            'reference' => (string) $order->ppa_ref,
            'countryId' => (int) $order->ped_id_pais,
            'origin' => (string) ($order->ped_origen ?? ''),
            'status' => (string) ($order->ped_estatus ?? ''),
            'productStatus' => (string) ($order->ped_estatus_productos ?? ''),
            'checkout' => (string) ($order->ped_checkout ?? ''),
            'createdAt' => (string) ($order->ped_fecha ?? ''),
            'paidAt' => (string) ($order->ppa_fecha ?? ''),
            'processedAt' => (string) ($order->ppa_fecha_procesado ?? ''),
            'deliveredAt' => (string) ($order->ppa_fecha_entregado ?? ''),
            'customer' => [
                'name' => trim((string) ($order->ped_nombres ?? '').' '.(string) ($order->ped_apellidos ?? '')),
                'email' => (string) ($order->ped_email ?? ''),
                'identificationType' => (string) ($order->ped_tipo_identificacion ?? ''),
                'identification' => (string) ($order->ped_identificacion ?? ''),
                'rtu' => (string) ($order->ped_rtu ?? ''),
                'phone' => trim((string) ($order->ped_telefono_pais ?? '').' '.(string) ($order->ped_telefono ?? '')),
                'whatsapp' => trim((string) ($order->ped_whatsapp_pais ?? '').' '.(string) ($order->ped_whatsapp ?? '')),
                'billingAddress' => $this->joinAddress([
                    $order->ped_direccion ?? null,
                    $order->ped_ciudad ?? null,
                    $order->ped_estado ?? null,
                    $order->ped_pais ?? null,
                ]),
            ],
            'payment' => [
                'type' => (string) ($order->ppa_tipo ?? ''),
                'status' => (string) ($order->ppa_estado ?? ''),
                'issuer' => (string) ($order->ppa_emisor ?? ''),
                'card' => (string) ($order->ppa_tarjeta ?? ''),
                'authorization' => (string) ($order->ppa_autorizacion ?? ''),
                'change' => (float) ($order->ppa_cambio ?? 0),
                'ticket' => (string) ($order->ppa_ticket ?? ''),
                'responseCode' => (string) ($order->ppa_rsp_codigo ?? ''),
                'message' => (string) ($order->mfa_mensaje ?? ''),
            ],
            'shipping' => [
                'id' => $order->pdi_id !== null ? (int) $order->pdi_id : null,
                'shippingId' => (string) ($order->pdi_id_shipping ?? ''),
                'addressId' => $order->dir_id !== null ? (int) $order->dir_id : null,
                'address' => $this->joinAddress([
                    $order->dir_direccion ?? null,
                    $order->dir_municipio_txt ?? null,
                    $order->dir_departamento_txt ?? null,
                ]),
                'reference' => (string) ($order->dir_referencia ?? ''),
                'lat' => (string) ($order->dir_latitud ?? ''),
                'lng' => (string) ($order->dir_longitud ?? ''),
                'cost' => $shipping,
                'routeAt' => (string) ($order->pdi_fecha_ruta ?? ''),
                'samePerson' => (string) ($order->dir_misma_persona ?? ''),
                'receiverName' => (string) ($order->dir_persona ?? ''),
                'receiverPhone' => (string) ($order->dir_telefono ?? ''),
            ],
            'storePickup' => [
                'storeCode' => (string) ($order->tie_codigo ?? $order->ped_tienda ?? ''),
                'storeName' => (string) ($order->tie_nombre ?? ''),
                'samePerson' => (string) ($order->pti_misma_persona ?? ''),
                'person' => (string) ($order->pti_persona ?? ''),
                'phone' => (string) ($order->pti_telefono ?? ''),
                'identification' => (string) ($order->pti_identificacion ?? ''),
            ],
            'totals' => [
                'items' => $items,
                'itemsOriginal' => (int) ($order->ppa_articulos ?? 0),
                'itemsBilled' => $itemsBilled,
                'itemsBilledOriginal' => (int) ($order->ppa_articulos_final ?? 0),
                'products' => $productsTotal,
                'productsOriginal' => $productsOriginal,
                'productsDifference' => round($productsTotal - $productsOriginal, 2),
                'shipping' => (string) ($order->ped_checkout ?? '') === 'DOMICILIO' ? $shipping : 0.0,
                'paid' => $paid,
                'paidCalculated' => $paidCalculated,
                'paidDifference' => round($paidCalculated - $paid, 2),
                'discount' => (float) ($order->ppa_promo_descuento ?? 0),
                'refund' => (float) ($order->ped_monto_devolucion ?? 0),
                'billed' => round(max(0, $billedSubtotal + ((string) ($order->ped_checkout ?? '') === 'DOMICILIO' ? $shipping : 0)), 2),
            ],
        ];
    }

    private function normalizeProduct(object $product): array
    {
        $quantity = (int) ($product->car_cantidad ?? 0);
        $billedQuantity = $product->car_total_facturado !== null ? (int) $product->car_total_facturado : null;
        $price = (float) ($product->car_precio ?? $product->ppa_precio ?? 0);
        $discount = (float) ($product->car_descuento ?? 0);
        $billedDiscount = (float) ($product->car_descuento_final ?? $discount);
        $hasSubstitute = filled($product->car_estilo_final)
            && ((string) $product->pro_codigo !== (string) $product->car_estilo_final
                || (string) $product->car_talla !== (string) $product->car_talla_final);

        return [
            'id' => (int) $product->car_id,
            'sku' => (string) $product->pro_codigo,
            'name' => (string) $product->pro_nombre,
            'size' => (string) ($product->car_talla ?? ''),
            'quantity' => $quantity,
            'billedQuantity' => $billedQuantity,
            'price' => $price,
            'discount' => $discount,
            'billedDiscount' => $billedDiscount,
            'chargedSubtotal' => $quantity * ($price * (1 - ($discount / 100))),
            'billedSubtotal' => ($billedQuantity ?? 0) * ($price * (1 - ($billedDiscount / 100))),
            'promotionId' => $product->car_promocion_id !== null ? (int) $product->car_promocion_id : null,
            'promotion' => (string) ($product->car_promocion ?? ''),
            'substitute' => [
                'hasSubstitute' => $hasSubstitute,
                'sku' => (string) ($product->car_estilo_final ?? ''),
                'name' => (string) ($product->estilo_final_nombre ?? ''),
                'size' => (string) ($product->car_talla_final ?? ''),
            ],
        ];
    }

    /**
     * @return array{id: int, sku: string, name: string, price: float, status: string, sizes: array<int, string>}
     */
    private function resolveActiveProduct(string $sku, int $countryId): array
    {
        $product = DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->where('p.pro_codigo', trim($sku))
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_nombre',
                'p.pro_tallas',
                'pp.ppa_precio',
                'pp.ppa_estado',
            ])
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'sku' => 'El articulo no existe o no esta activo para el pais del pedido.',
            ]);
        }

        return [
            'id' => (int) $product->pro_id,
            'sku' => (string) $product->pro_codigo,
            'name' => (string) $product->pro_nombre,
            'price' => (float) $product->ppa_precio,
            'status' => (string) $product->ppa_estado,
            'sizes' => $this->sizes((string) ($product->pro_tallas ?? '')),
        ];
    }

    /**
     * @param array{id: int, sku: string, name: string, price: float, status: string, sizes: array<int, string>} $product
     */
    private function ensureValidSize(array $product, string $size): void
    {
        $size = strtoupper(trim($size));
        $sizes = array_map(fn (string $value) => strtoupper($value), $product['sizes']);

        if ($sizes !== [] && ! in_array($size, $sizes, true)) {
            throw ValidationException::withMessages([
                'size' => 'La talla no existe para el articulo seleccionado. Tallas validas: '.implode(', ', $product['sizes']),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function sizes(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn (string $size) => trim($size))
            ->filter()
            ->values()
            ->all();
    }

    private function joinAddress(array $parts): string
    {
        return collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');
    }

    private function resolveCountryId(string $country): int
    {
        $country = trim($country);
        $query = DB::table('stj_paises')->select(['pai_id']);

        $resolved = is_numeric($country)
            ? $query->where('pai_id', (int) $country)->first()
            : $query->where('pai_codigo', strtoupper($country))->first();

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return (int) $resolved->pai_id;
    }
}
