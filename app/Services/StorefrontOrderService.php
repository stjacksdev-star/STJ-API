<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StorefrontOrderService
{
    private $checkoutValidationService;

    public function __construct(StorefrontCheckoutValidationService $checkoutValidationService)
    {
        $this->checkoutValidationService = $checkoutValidationService;
    }

    public function create(array $payload): array
    {
        $validation = $this->checkoutValidationService->validate(
            $payload['country'],
            $payload['fulfillment'],
            $payload['items'],
        );

        if (! (bool) ($validation['ok'] ?? false)) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => $validation['message'] ?? 'No se pudo validar el checkout.',
                'validation' => $validation,
            ];
        }

        $country = $this->resolveCountry($payload['country']);

        if (! $country) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Pais no soportado para crear pedido.',
            ];
        }

        $products = $this->resolveProducts((int) $country->pai_id, $payload['items']);

        if (count($products) !== count(collect($payload['items'])->pluck('sku')->unique())) {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Uno o mas productos del carrito ya no estan disponibles.',
            ];
        }

        $order = DB::transaction(function () use ($payload, $country, $products) {
            $now = now();
            $checkoutType = $payload['fulfillment']['method'] === 'store_pickup' ? 'TIENDA' : 'DOMICILIO';
            $storeCode = $this->resolveStoreCode(strtolower((string) $country->pai_codigo), $payload['fulfillment']);
            $paymentRef = $this->generatePaymentRef();
            $items = $this->normalizeItems($payload['items'], $products);
            $subtotal = collect($items)->sum(function (array $item) {
                return round(((float) $item['price']) * ((int) $item['quantity']), 2);
            });
            $articleCount = collect($items)->sum('quantity');
            $customer = $payload['customer'];
            $delivery = $payload['fulfillment'];

            $pedidoId = DB::table('stj_pedidos')->insertGetId([
                'ped_id_pais' => (int) $country->pai_id,
                'ped_origen' => 'WEB',
                'ped_fecha' => $now,
                'ped_estatus' => 'PENDIENTE_PAGO',
                'ped_estatus_productos' => 'COMPLETO',
                'ped_checkout' => $checkoutType,
                'ped_tienda' => $storeCode,
                'ped_login' => 'INVITADO',
                'ped_user' => null,
                'ped_sesion' => $payload['guestCartId'],
                'ped_nombres' => $this->limit($customer['firstName'] ?? '', 30),
                'ped_apellidos' => $this->limit($customer['lastName'] ?? '', 30),
                'ped_email' => $this->limit($customer['email'] ?? '', 50),
                'ped_tipo_identificacion' => 'DUI',
                'ped_identificacion' => $this->limit($customer['document'] ?? '', 50),
                'ped_rtu' => '',
                'ped_pais' => strtoupper((string) $country->pai_codigo),
                'ped_departamento' => null,
                'ped_municipio' => null,
                'ped_estado' => null,
                'ped_ciudad' => $this->limit($delivery['city'] ?? '', 50),
                'ped_direccion' => $this->limit($delivery['addressLine1'] ?? '', 200),
                'ped_telefono_pais' => $this->phonePrefix(strtolower((string) $country->pai_codigo)),
                'ped_telefono' => $this->limit($customer['phone'] ?? '', 30),
                'ped_whatsapp_pais' => $this->phonePrefix(strtolower((string) $country->pai_codigo)),
                'ped_whatsapp' => $this->limit($customer['phone'] ?? '', 30),
                'ped_devolucion_realizada' => 'N/A',
                'ped_rsp_servicio' => null,
                'ped_monto_devolucion' => null,
                'ped_correo_enviado' => 'NO',
                'ped_a_usuario' => 'storefront',
                'ped_a_ip' => request()->ip(),
                'ped_a_generales' => $this->limit($payload['notes'] ?? '', 500),
                'ped_a_fecha' => $now,
                'ped_a_version' => 1,
                'ped_credito_fiscal' => 'NO',
                'ped_vapp' => null,
                'ped_suscrito_mailing' => 'NO',
            ]);

            if ($checkoutType === 'DOMICILIO') {
                $direccionId = DB::table('stj_direcciones')->insertGetId([
                    'dir_tipo' => 'CASA',
                    'dir_misma_persona' => 'SI',
                    'dir_misma_direccion' => 'SI',
                    'dir_fecha' => $now,
                    'dir_usuario' => null,
                    'dir_pais' => strtoupper((string) $country->pai_codigo),
                    'dir_direccion' => $this->limit($delivery['addressLine1'] ?? '', 200),
                    'dir_referencia' => $this->limit($delivery['reference'] ?? '', 200),
                    'dir_departamento_txt' => $this->limit($delivery['state'] ?? '', 50),
                    'dir_municipio_txt' => $this->limit($delivery['city'] ?? '', 50),
                    'dir_persona' => $this->limit(trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? '')), 100),
                    'dir_telefono' => $this->limit($customer['phone'] ?? '', 100),
                    'dir_save' => 'AUTOMATICO',
                    'dir_a_usuario' => 'storefront',
                    'dir_a_ip' => request()->ip(),
                    'dir_a_fecha' => $now,
                    'dir_a_version' => 1,
                ]);

                DB::table('stj_pedidos_direccion')->insert([
                    'pdi_pedido' => $pedidoId,
                    'pdi_direccion' => $direccionId,
                    'pdi_tipo_envio' => 'DOMICILIO',
                    'pdi_id_urbano' => '0',
                    'pdi_id_shipping' => null,
                    'pdi_costo_envio' => '0',
                    'pdi_costo_envio_txt' => 'Pendiente de calcular',
                    'pdi_costo_envio_final' => '0',
                    'pdi_aplica_envio_gratis' => 'NO',
                    'pdi_a_usuario' => 'storefront',
                    'pdi_a_ip' => request()->ip(),
                    'pdi_a_fecha' => $now,
                    'pdi_a_version' => 1,
                ]);
            } else {
                DB::table('stj_pedidos_tienda')->insert([
                    'pti_pedido' => $pedidoId,
                    'pti_misma_persona' => 'SI',
                    'pti_pais' => strtoupper((string) $country->pai_codigo),
                    'pti_tienda' => $storeCode,
                    'pti_persona' => $this->limit(trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? '')), 100),
                    'pti_telefono' => $this->limit($customer['phone'] ?? '', 100),
                    'pti_identificacion' => $this->limit($customer['document'] ?? '', 50),
                    'pti_a_usuario' => 'storefront',
                    'pti_a_ip' => request()->ip(),
                    'pti_a_fecha' => $now,
                    'pti_a_version' => 1,
                ]);
            }

            $pagoId = DB::table('stj_pedidos_pago')->insertGetId([
                'ppa_tipo' => 'TARJETA',
                'ppa_estado' => 'PENDIENTE',
                'ppa_ref' => $paymentRef,
                'ppa_fecha' => $now,
                'ppa_pedido' => $pedidoId,
                'ppa_emisor' => 'OTRO',
                'ppa_tarjeta' => 'XXXXXX',
                'ppa_monto_sdesc' => $subtotal,
                'ppa_monto_senv' => $subtotal,
                'ppa_monto' => $subtotal,
                'ppa_articulos' => $articleCount,
                'ppa_pagado' => 'N/A',
                'ppa_a_usuario' => 'storefront',
                'ppa_a_ip' => request()->ip(),
                'ppa_a_fecha' => $now,
                'ppa_a_version' => 1,
            ]);

            $detailRows = collect($items)->map(function (array $item) use ($country, $checkoutType, $payload, $paymentRef, $now) {
                return [
                    'car_pais' => (int) $country->pai_id,
                    'car_tipo' => $checkoutType,
                    'car_accion' => 'AGREGADO',
                    'car_sesion' => null,
                    'car_usuario' => null,
                    'car_fecha' => $now,
                    'car_producto' => $item['productId'],
                    'car_precio' => $item['price'],
                    'car_talla' => $item['size'],
                    'car_cantidad' => $item['quantity'],
                    'car_descuento' => 0,
                    'car_promocion' => null,
                    'car_promocion_id' => null,
                    'car_ref' => $paymentRef,
                    'car_total_facturado' => $item['quantity'],
                    'car_descuento_final' => 0,
                    'car_estilo_final' => $item['sku'],
                    'car_talla_final' => $item['size'],
                    'car_modificar' => 'SI',
                    'car_a_usuario' => 'storefront',
                    'car_a_ip' => request()->ip(),
                    'car_a_generales' => $this->limit($payload['guestCartId'], 500),
                    'car_a_fecha' => $now,
                    'car_a_version' => 1,
                    'car_origen' => 1,
                    'car_selected' => 1,
                ];
            })->all();

            DB::table('stj_pedidos_detalle')->insert($detailRows);

            return [
                'pedidoId' => $pedidoId,
                'pagoId' => $pagoId,
                'paymentRef' => $paymentRef,
                'status' => 'PENDIENTE_PAGO',
                'paymentStatus' => 'PENDIENTE',
                'checkoutType' => $checkoutType,
                'storeCode' => $storeCode,
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
                'articleCount' => $articleCount,
                'items' => $items,
            ];
        });

        return [
            'ok' => true,
            'status' => 201,
            'message' => 'Pedido pendiente creado correctamente.',
            'order' => $order,
            'validation' => $validation,
        ];
    }

    private function resolveCountry(string $countryCode): ?object
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo'])
            ->where('pai_codigo', strtoupper($countryCode))
            ->first();
    }

    private function resolveProducts(int $countryId, array $items)
    {
        $skus = collect($items)
            ->pluck('sku')
            ->map(fn ($sku) => trim((string) $sku))
            ->filter()
            ->unique()
            ->values();

        return DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->where('pp.ppa_pais', $countryId)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO')
            ->whereIn('p.pro_codigo', $skus)
            ->select(['p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'pp.ppa_precio'])
            ->get()
            ->keyBy(fn ($product) => trim((string) $product->pro_codigo));
    }

    private function normalizeItems(array $items, $products): array
    {
        return collect($items)
            ->map(function (array $item) use ($products) {
                $sku = trim((string) $item['sku']);
                $product = $products->get($sku);

                return [
                    'key' => $item['key'] ?? "{$sku}:".trim((string) $item['size']),
                    'productId' => (int) $product->pro_id,
                    'sku' => $sku,
                    'name' => trim((string) ($product->pro_nombre ?: ($item['name'] ?? $sku))),
                    'size' => trim((string) $item['size']),
                    'quantity' => max(1, (int) $item['quantity']),
                    'price' => round((float) $product->ppa_precio, 2),
                ];
            })
            ->values()
            ->all();
    }

    private function resolveStoreCode(string $countryCode, array $fulfillment): string
    {
        if (($fulfillment['method'] ?? '') === 'store_pickup') {
            return trim((string) ($fulfillment['storeCode'] ?? ''));
        }

        return trim((string) config("inventory.domicilio_store_by_country.{$countryCode}", config("inventory.default_store_by_country.{$countryCode}", '')));
    }

    private function generatePaymentRef(): string
    {
        do {
            $ref = 'STJ'.now()->format('ymdHis').strtoupper(Str::random(4));
        } while (DB::table('stj_pedidos_pago')->where('ppa_ref', $ref)->exists());

        return $ref;
    }

    private function phonePrefix(string $countryCode): string
    {
        return [
            'sv' => '503',
            'gt' => '502',
            'cr' => '506',
            'pa' => '507',
            'hn' => '504',
            'do' => '1',
        ][$countryCode] ?? '';
    }

    private function limit(?string $value, int $length): string
    {
        return Str::limit(trim((string) $value), $length, '');
    }
}
