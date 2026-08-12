<?php

namespace App\Services;

use App\Exceptions\CartOperationConflict;
use App\Models\CustomerEvent;
use App\Models\StorefrontCart;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontOrderService
{
    private $checkoutValidationService;

    public function __construct(
        StorefrontCheckoutValidationService $checkoutValidationService,
        private StorefrontProductPricingService $pricing,
        private ?StorefrontShippingService $shipping = null,
        private ?StorefrontPromotionResolver $promotionResolver = null,
        private ?WebPushDeliveryCancellationService $pushDeliveryCancellation = null,
        private ?WebPushMeasurementService $pushMeasurements = null,
    ) {
        $this->checkoutValidationService = $checkoutValidationService;
    }

    public function createFromCart(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $payload): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $payload) {
            $country = $this->resolveCountry($countryCode);
            if (! $country) {
                throw ValidationException::withMessages(['country' => 'Pais no soportado.']);
            }
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $operation = DB::table('stj_carrito_operaciones')->where('cao_uuid', $payload['operation_uuid'])->lockForUpdate()->first();
            if ($operation) {
                $operationCart = StorefrontCart::query()->whereKey($operation->cao_carrito_id)->where('car_pais_id', $country->pai_id);
                $customer ? $operationCart->where('car_usu_id', $customer->getKey()) : $operationCart->whereNull('car_usu_id')->where('car_visitante_id', $visitor->getKey());
                if (! $operationCart->exists() || ! hash_equals((string) $operation->cao_payload_hash, $hash)) {
                    throw new CartOperationConflict('operation_uuid ya fue utilizado con otro contenido o carrito.');
                }

                return json_decode($operation->cao_respuesta, true);
            }

            $query = StorefrontCart::query()->where('car_pais_id', $country->pai_id)->where('car_estado', 'CHECKOUT')->orderByDesc('car_id');
            $customer ? $query->where('car_usu_id', $customer->getKey()) : $query->whereNull('car_usu_id')->where('car_visitante_id', $visitor->getKey());
            $cart = $query->lockForUpdate()->first();
            if (! $cart) {
                throw ValidationException::withMessages(['cart' => 'No existe un checkout autorizado para esta identidad.']);
            }
            if ($cart->car_estado !== 'CHECKOUT' || $cart->car_pedido_id) {
                throw ValidationException::withMessages(['cart' => 'El carrito ya fue convertido o no continua en checkout.']);
            }
            $checkoutOperation = DB::table('stj_carrito_operaciones')->where('cao_carrito_id', $cart->getKey())->where('cao_tipo', 'CHECKOUT_START')->orderByDesc('cao_id')->first();
            $authorizedCheckout = $checkoutOperation ? json_decode((string) $checkoutOperation->cao_respuesta, true) : null;
            $deliveryForHash = $cart->car_tipo === 'TIENDA' ? [] : ($payload['delivery'] ?? []);
            if (! hash_equals((string) data_get($authorizedCheckout, 'checkout.destinationHash', ''), $this->destinationHash($deliveryForHash))) {
                throw ValidationException::withMessages(['delivery' => 'El destino cambio despues de validar el checkout. Valida nuevamente.']);
            }
            $store = DB::table('stj_tiendas')->where('tie_id', $cart->car_tienda_id)->where('tie_pais', $cart->car_pais_id)->where('tie_codigo', (string) $cart->car_tienda_codigo_snapshot)->first();
            if (! $store) {
                throw ValidationException::withMessages(['fulfillment' => 'La fuente operativa del carrito ya no es valida.']);
            }
            $storeCode = (string) $store->tie_codigo;
            if (mb_strlen($storeCode) > 10) {
                throw ValidationException::withMessages(['fulfillment' => 'El codigo operativo excede la capacidad actual de stj_pedidos.ped_tienda.']);
            }
            if ($cart->car_tipo === 'DOMICILIO' && $storeCode !== (string) config('inventory.domicilio_store_by_country.'.strtolower((string) $country->pai_codigo))) {
                throw ValidationException::withMessages(['fulfillment' => 'La fuente de Domicilio no coincide con la configuracion autorizada.']);
            }
            if ($cart->car_tipo === 'DOMICILIO' && trim((string) data_get($payload, 'delivery.addressLine1')) === '') {
                throw ValidationException::withMessages(['delivery.addressLine1' => 'La direccion es obligatoria para entrega a domicilio.']);
            }
            $paymentType = strtoupper((string) ($payload['payment_type'] ?? 'TARJETA'));
            if (! in_array($paymentType, ['TARJETA', 'EFECTIVO'], true)) {
                throw ValidationException::withMessages(['payment_type' => 'El metodo de pago no es valido.']);
            }
            if ($cart->car_tipo !== 'TIENDA' && $paymentType !== 'TARJETA') {
                throw ValidationException::withMessages(['payment_type' => 'El pago en efectivo solo esta disponible para retiro en tienda.']);
            }
            $pickup = $payload['pickup'] ?? [];
            $samePickupPerson = (bool) ($pickup['samePerson'] ?? true);
            if ($cart->car_tipo === 'TIENDA' && ! $samePickupPerson) {
                foreach (['person' => 'nombre', 'phone' => 'telefono', 'identification' => 'identificacion'] as $field => $label) {
                    if (trim((string) ($pickup[$field] ?? '')) === '') {
                        throw ValidationException::withMessages(["pickup.{$field}" => "La {$label} de quien retirara es obligatoria."]);
                    }
                }
            }
            // Revalidate the complete cart again immediately before persisting the
            // order. A stale unavailable flag must never make a line disappear.
            $lines = $cart->items()->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'No hay lineas para crear el pedido.']);
            }
            $trustedItems = $lines->map(fn ($line) => ['key' => (string) $line->getKey(), 'sku' => (string) $line->cad_ref, 'name' => (string) $line->cad_ref, 'size' => (string) $line->cad_talla, 'quantity' => (int) $line->cad_cantidad])->all();
            $method = $cart->car_tipo === 'TIENDA' ? 'store_pickup' : 'home_delivery';
            $validation = $this->checkoutValidationService->validate($countryCode, ['method' => $method, 'storeCode' => $storeCode], $trustedItems);
            if (! ($validation['ok'] ?? false)) {
                $failures = collect($validation['lines'] ?? [])->filter(fn (array $line) => ! ($line['ok'] ?? false))
                    ->map(fn (array $line) => sprintf('%s, talla %s: solicitadas %d, disponibles %d', (string) ($line['name'] ?? $line['sku'] ?? 'Producto'), (string) ($line['size'] ?? ''), (int) ($line['requestedQuantity'] ?? 0), (int) ($line['availableQuantity'] ?? 0)));
                $message = (string) ($validation['message'] ?? 'El inventario cambio durante checkout.');
                throw ValidationException::withMessages(['inventory' => $failures->isEmpty() ? $message : $message.' '.$failures->implode('; ').'.']);
            }
            $trustedPayload = ['country' => $countryCode, 'guestCartId' => (string) $cart->car_uuid, 'customerId' => $customer?->getKey(), 'customer' => $payload['customer'], 'fulfillment' => ['method' => $method, 'storeCode' => $storeCode, 'storeName' => (string) $store->tie_nombre, ...($payload['delivery'] ?? [])], 'pickup' => $pickup, 'notes' => $payload['notes'] ?? null, 'items' => $trustedItems, 'paymentType' => $paymentType];
            $result = $this->create($trustedPayload);
            if (! ($result['ok'] ?? false)) {
                throw ValidationException::withMessages(['order' => $result['message'] ?? 'No se pudo crear el pedido.']);
            }
            $orderId = (int) $result['order']['pedidoId'];
            $cart->forceFill(['car_pedido_id' => $orderId, 'car_estado' => 'CONVERTIDO', 'car_convertido_en' => now(), 'car_version' => $cart->car_version + 1, 'car_actualizado_en' => now()])->save();
            $this->pushCancellation()->cancelAllPendingCartDeliveries((int) $cart->getKey(), 'El carrito fue convertido en pedido.');
            $this->pushMeasurements()->recordCartConverted((int) $cart->getKey(), $orderId);
            DB::table('stj_carrito_auditoria')->insert(['cau_carrito_id' => $cart->getKey(), 'cau_visitante_id' => $visitor->getKey(), 'cau_usu_id' => $customer?->getKey(), 'cau_accion' => 'ORDER_CREATED', 'cau_origen' => 'WEB', 'cau_datos_anteriores' => json_encode(['state' => 'CHECKOUT']), 'cau_datos_nuevos' => json_encode(['state' => 'CONVERTIDO', 'orderId' => $orderId]), 'cau_ocurrido_en' => now()]);
            CustomerEvent::query()->create(['cev_event_uuid' => $payload['operation_uuid'], 'cev_visitante_id' => $visitor->getKey(), 'cev_usu_id' => $customer?->getKey(), 'cev_pais_id' => $cart->car_pais_id, 'cev_carrito_id' => $cart->getKey(), 'cev_pedido_id' => $orderId, 'cev_tipo' => 'ORDER_CREATED', 'cev_valor' => $result['order']['total'], 'cev_moneda' => $cart->car_moneda, 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now(), 'cev_recibido_en' => now()]);
            DB::table('stj_carrito_operaciones')->insert(['cao_uuid' => $payload['operation_uuid'], 'cao_carrito_id' => $cart->getKey(), 'cao_visitante_id' => $visitor->getKey(), 'cao_usu_id' => $customer?->getKey(), 'cao_tipo' => 'ORDER_CREATE', 'cao_payload_hash' => $hash, 'cao_respuesta' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'cao_creado_en' => now()]);
            if ($customer && data_get($result, 'order.paymentStatus') === 'APROBADA') {
                StorefrontRecommendationService::forgetPurchaseHistory((int) $customer->getKey(), (int) $cart->car_pais_id);
            }

            return $result;
        });
    }

    private function pushCancellation(): WebPushDeliveryCancellationService
    {
        return $this->pushDeliveryCancellation ??= app(WebPushDeliveryCancellationService::class);
    }

    private function pushMeasurements(): WebPushMeasurementService
    {
        return $this->pushMeasurements ??= app(WebPushMeasurementService::class);
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
            $items = $this->normalizeItems($payload['items'], $products, (int) $country->pai_id);
            $store = $checkoutType === 'TIENDA'
                ? DB::table('stj_tiendas')->where('tie_pais', $country->pai_id)->where('tie_codigo', $storeCode)->first(['tie_id', 'tie_nombre'])
                : null;
            $resolution = ($this->promotionResolver ?? app(StorefrontPromotionResolver::class))->resolve([
                'countryId' => (int) $country->pai_id,
                'checkoutType' => $checkoutType,
                'storeId' => $store?->tie_id,
                'storeName' => $payload['fulfillment']['storeName'] ?? $store?->tie_nombre,
                'currencySymbol' => $this->currencySymbol(strtoupper((string) $country->pai_codigo)),
                'at' => $now,
                'lines' => collect($items)->map(fn (array $item) => [
                    'key' => (string) $item['key'],
                    'productId' => $item['productId'],
                    'quantity' => $item['quantity'],
                    'unitPrice' => $item['regularPrice'],
                ])->all(),
            ]);
            $resolvedLines = collect($resolution['lines'])->keyBy('key');
            $items = collect($items)->map(function (array $item) use ($resolvedLines) {
                $resolved = $resolvedLines->get((string) $item['key']);
                $baseTotal = (string) ($resolved['baseTotal'] ?? $this->decimal($this->cents($item['regularPrice']) * $item['quantity']));
                $discount = (string) ($resolved['discount'] ?? '0.00');
                $finalTotal = (string) ($resolved['finalTotal'] ?? $baseTotal);

                return [
                    ...$item,
                    'baseTotal' => $baseTotal,
                    'discount' => $discount,
                    'finalTotal' => $finalTotal,
                    'finalUnitPrice' => $this->decimal((int) round($this->cents($finalTotal) / $item['quantity'])),
                    'price' => $this->decimal((int) round($this->cents($finalTotal) / $item['quantity'])),
                    'promotion' => $resolved['promotion'] ?? null,
                ];
            })->all();
            $baseSubtotalCents = collect($items)->sum(fn (array $item) => $this->cents($item['baseTotal']));
            $discountCents = collect($items)->sum(fn (array $item) => $this->cents($item['discount']));
            $subtotalCents = collect($items)->sum(fn (array $item) => $this->cents($item['finalTotal']));
            $baseSubtotal = $this->decimal($baseSubtotalCents);
            $subtotal = $this->decimal($subtotalCents);
            $shipping = ($this->shipping ?? app(StorefrontShippingService::class))->quote($country, $checkoutType, data_get($payload, 'fulfillment.city_id'), $subtotal);
            $shippingCents = $this->cents((string) $shipping['shipping_amount']);
            $total = $this->decimal($subtotalCents + $shippingCents);
            $articleCount = collect($items)->sum('quantity');
            $customer = $payload['customer'];
            $delivery = $payload['fulfillment'];
            $paymentType = $payload['paymentType'] ?? 'TARJETA';
            $orderStatus = $paymentType === 'EFECTIVO' ? 'RECIBIDO' : 'PENDIENTE_PAGO';
            $residenceCountry = DB::table('stj_world_countries')->where('id', $customer['countryId'])->first(['id', 'name', 'phonecode']);
            $residenceState = $residenceCountry ? DB::table('stj_world_states')
                ->where('id', $customer['stateId'])
                ->where('country_id', $residenceCountry->id)
                ->first(['id', 'name']) : null;
            $residenceCity = $residenceState ? DB::table('stj_world_cities')
                ->where('id', $customer['cityId'])
                ->where('state_id', $residenceState->id)
                ->where('country_id', $residenceCountry->id)
                ->first(['id', 'name']) : null;
            if (! $residenceCountry || ! $residenceState || ! $residenceCity) {
                throw ValidationException::withMessages(['customer.address' => 'La ubicación de residencia seleccionada no es válida.']);
            }

            $pedidoId = DB::table('stj_pedidos')->insertGetId([
                'ped_id_pais' => (int) $country->pai_id,
                'ped_origen' => 'WEB',
                'ped_fecha' => $now,
                'ped_estatus' => $orderStatus,
                'ped_estatus_productos' => 'COMPLETO',
                'ped_checkout' => $checkoutType,
                'ped_tienda' => $storeCode,
                'ped_login' => ! empty($payload['customerId']) ? 'CLIENTE' : 'INVITADO',
                'ped_user' => $payload['customerId'] ?? null,
                'ped_sesion' => $payload['guestCartId'],
                'ped_nombres' => $this->limit($customer['firstName'] ?? '', 30),
                'ped_apellidos' => $this->limit($customer['lastName'] ?? '', 30),
                'ped_email' => $this->limit($customer['email'] ?? '', 50),
                'ped_tipo_identificacion' => $this->limit($customer['documentType'], 50),
                'ped_identificacion' => $this->limit($customer['document'] ?? '', 50),
                'ped_rtu' => '',
                'ped_pais' => $this->limit($residenceCountry->name, 100),
                'ped_departamento' => $residenceState->id,
                'ped_municipio' => $residenceCity->id,
                'ped_estado' => $this->limit($residenceState->name, 50),
                'ped_ciudad' => $this->limit($residenceCity->name, 50),
                'ped_direccion' => $this->limit($customer['address'], 200),
                'ped_telefono_pais' => ltrim((string) $residenceCountry->phonecode, '+'),
                'ped_telefono' => $this->limit($customer['phone'] ?? '', 30),
                'ped_whatsapp_pais' => ltrim((string) $residenceCountry->phonecode, '+'),
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
                    'dir_departamento' => $shipping['city']['stateId'],
                    'dir_municipio' => $shipping['city']['id'],
                    'dir_departamento_txt' => $this->limit($shipping['city']['state'], 50),
                    'dir_municipio_txt' => $this->limit($shipping['city']['name'], 50),
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
                    'pdi_id_urbano' => (string) ($shipping['city']['urbanId'] ?? 0),
                    'pdi_id_shipping' => $shipping['rule_id'] ? (string) $shipping['rule_id'] : null,
                    'pdi_costo_envio' => $shipping['shipping_amount'],
                    'pdi_costo_envio_txt' => $shipping['display_amount'],
                    'pdi_costo_envio_final' => $shipping['shipping_amount'],
                    'pdi_aplica_envio_gratis' => $shippingCents === 0 ? 'SI' : 'NO',
                    'pdi_monto_minimo_envio' => $shipping['minimum_free_shipping'],
                    'pdi_falta_envio_gratis' => $shipping['remaining_for_free_shipping'],
                    'pdi_moneda_envio' => $shipping['currency_symbol'],
                    'pdi_mensaje_envio' => $this->limit($shipping['message'], 255),
                    'pdi_a_usuario' => 'storefront',
                    'pdi_a_ip' => request()->ip(),
                    'pdi_a_fecha' => $now,
                    'pdi_a_version' => 1,
                ]);
            } else {
                DB::table('stj_pedidos_tienda')->insert([
                    'pti_pedido' => $pedidoId,
                    'pti_misma_persona' => ($payload['pickup']['samePerson'] ?? true) ? 'SI' : 'NO',
                    'pti_pais' => strtoupper((string) $country->pai_codigo),
                    'pti_tienda' => $storeCode,
                    'pti_persona' => $this->limit(($payload['pickup']['samePerson'] ?? true) ? trim(($customer['firstName'] ?? '').' '.($customer['lastName'] ?? '')) : ($payload['pickup']['person'] ?? ''), 100),
                    'pti_telefono' => $this->limit(($payload['pickup']['samePerson'] ?? true) ? ($customer['phone'] ?? '') : ($payload['pickup']['phone'] ?? ''), 100),
                    'pti_identificacion' => $this->limit(($payload['pickup']['samePerson'] ?? true) ? ($customer['document'] ?? '') : ($payload['pickup']['identification'] ?? ''), 50),
                    'pti_a_usuario' => 'storefront',
                    'pti_a_ip' => request()->ip(),
                    'pti_a_fecha' => $now,
                    'pti_a_version' => 1,
                ]);
            }

            $pagoId = DB::table('stj_pedidos_pago')->insertGetId([
                'ppa_tipo' => $paymentType,
                'ppa_estado' => $paymentType === 'EFECTIVO' ? 'APROBADA' : 'PENDIENTE',
                'ppa_ref' => $paymentRef,
                'ppa_fecha' => $now,
                'ppa_pedido' => $pedidoId,
                'ppa_emisor' => 'OTRO',
                'ppa_autorizacion' => $paymentType === 'EFECTIVO' ? 'Efectivo' : null,
                'ppa_tarjeta' => $paymentType === 'EFECTIVO' ? null : 'XXXXXX',
                'ppa_monto_sdesc' => $baseSubtotal,
                'ppa_monto_senv' => $subtotal,
                'ppa_monto' => $total,
                'ppa_articulos' => $articleCount,
                'ppa_pagado' => $paymentType === 'EFECTIVO' ? 'NO' : 'N/A',
                'ppa_a_usuario' => 'storefront',
                'ppa_a_ip' => request()->ip(),
                'ppa_a_fecha' => $now,
                'ppa_a_version' => 1,
            ]);

            $detailRows = collect($items)->map(function (array $item) use ($country, $checkoutType, $payload, $paymentRef, $now) {
                $effectivePercentage = $this->cents($item['baseTotal']) > 0
                    ? round($this->cents($item['discount']) * 100 / $this->cents($item['baseTotal']), 2)
                    : 0;
                $promotion = $item['promotion'];

                return [
                    'car_pais' => (int) $country->pai_id,
                    'car_tipo' => $checkoutType,
                    'car_accion' => 'AGREGADO',
                    'car_sesion' => null,
                    'car_usuario' => $payload['customerId'] ?? null,
                    'car_fecha' => $now,
                    'car_producto' => $item['productId'],
                    'car_precio' => $item['regularPrice'],
                    'car_talla' => $item['size'],
                    'car_cantidad' => $item['quantity'],
                    'car_descuento' => $promotion['discountPercentage'] ?? $effectivePercentage,
                    'car_promocion' => $promotion ? $this->limit($promotion['commercialName'] ?: $promotion['name'], 250) : null,
                    'car_promocion_id' => $promotion['id'] ?? null,
                    'car_ref' => $paymentRef,
                    'car_total_facturado' => $item['quantity'],
                    'car_descuento_final' => $effectivePercentage,
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
                'status' => $orderStatus,
                'paymentStatus' => $paymentType === 'EFECTIVO' ? 'APROBADA' : 'PENDIENTE',
                'checkoutType' => $checkoutType,
                'storeCode' => $storeCode,
                'baseSubtotal' => $baseSubtotal,
                'discount' => $this->decimal($discountCents),
                'discountPercentage' => $baseSubtotalCents > 0 ? round($discountCents * 100 / $baseSubtotalCents, 2) : 0,
                'subtotal' => $subtotal,
                'shipping' => $shipping['shipping_amount'],
                'shippingSource' => $shipping['source'],
                'taxes' => strtoupper((string) $country->pai_codigo) === 'HN' ? $this->decimal((int) round(($subtotalCents + $shippingCents) * 15 / 115, 0, PHP_ROUND_HALF_UP)) : '0.00',
                'total' => $total,
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
            ->select(['pai_id', 'pai_id_world', 'pai_codigo'])
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

    private function normalizeItems(array $items, $products, int $countryId): array
    {
        return collect($items)
            ->map(function (array $item) use ($products, $countryId) {
                $sku = trim((string) $item['sku']);
                $product = $products->get($sku);

                $price = $this->pricing->resolve($countryId, (int) $product->pro_id, $sku, trim((string) $item['size']), now());
                if (! $price['ok']) {
                    throw ValidationException::withMessages(['price' => $price['message']]);
                }

                return [
                    'key' => $item['key'] ?? "{$sku}:".trim((string) $item['size']),
                    'productId' => (int) $product->pro_id,
                    'sku' => $sku,
                    'name' => trim((string) ($product->pro_nombre ?: ($item['name'] ?? $sku))),
                    'size' => trim((string) $item['size']),
                    'quantity' => max(1, (int) $item['quantity']),
                    'regularPrice' => $price['precio_regular'],
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

    private function limit(?string $value, int $length): string
    {
        return Str::limit(trim((string) $value), $length, '');
    }

    private function cents(string $value): int
    {
        [$whole,$fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function currencySymbol(string $countryCode): string
    {
        return [
            'SV' => '$',
            'GT' => 'Q',
            'CR' => '₡',
            'PA' => '$',
            'HN' => 'L',
            'DO' => 'RD$',
            'VE' => 'Bs.',
        ][strtoupper($countryCode)] ?? '$';
    }

    private function destinationHash(array $delivery): string
    {
        $normalized = ['city_id' => (int) ($delivery['city_id'] ?? 0), 'state_id' => (int) ($delivery['state_id'] ?? 0), 'address' => mb_strtolower(trim((string) ($delivery['addressLine1'] ?? ''))), 'reference' => mb_strtolower(trim((string) ($delivery['reference'] ?? '')))];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
