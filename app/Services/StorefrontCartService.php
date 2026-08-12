<?php

namespace App\Services;

use App\Exceptions\CartOperationConflict;
use App\Models\CustomerEvent;
use App\Models\StorefrontCart;
use App\Models\StorefrontCartAudit;
use App\Models\StorefrontCartItem;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Support\StorefrontImageUrl;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontCartService
{
    public function __construct(
        private ProductDetailAvailabilityService $availability,
        private StorefrontProductPricingService $pricing,
        private StorefrontFulfillmentService $fulfillment,
        private StorefrontCheckoutValidationService $checkoutValidation,
        private ?StorefrontShippingService $shipping = null,
        private ?StorefrontPromotionResolver $promotionResolver = null,
        private ?WebPushDeliveryCancellationService $pushDeliveryCancellation = null,
    ) {}

    public function startCheckout(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $input) {
            $cart = $this->resolveCart($countryCode, $visitor, $customer, false, false);
            if (! $cart) {
                throw ValidationException::withMessages(['cart' => 'No existe un carrito activo para iniciar checkout.']);
            }

            return $this->idempotent($cart, $visitor, $customer, 'CHECKOUT_START', $input['operation_uuid'], $input, function () use ($cart, $countryCode, $visitor, $customer, $input) {
                if (! in_array($cart->car_estado, ['ACTIVO', 'CHECKOUT'], true)) {
                    throw ValidationException::withMessages(['cart' => 'El carrito ya inicio checkout o no esta activo.']);
                }
                if (! $cart->car_tienda_id || ! $cart->car_tienda_codigo_snapshot || ! $cart->car_inventory_source) {
                    throw ValidationException::withMessages(['fulfillment' => 'El carrito no tiene un contexto de entrega completo.']);
                }
                // Every line still present in the cart is part of the purchase intent.
                // Do not silently omit a line previously marked unavailable/unselected:
                // checkout must revalidate it with the required `checkout` inventory scope.
                $lines = $cart->items()->lockForUpdate()->get();
                if ($lines->isEmpty()) {
                    throw ValidationException::withMessages(['cart' => 'El carrito no tiene lineas para validar.']);
                }
                $fulfillment = ['method' => $cart->car_tipo === 'TIENDA' ? 'store_pickup' : 'home_delivery', 'storeCode' => (string) $cart->car_tienda_codigo_snapshot];
                $items = $lines->map(fn ($line) => ['key' => $line->getKey(), 'sku' => (string) $line->cad_ref, 'name' => (string) $line->cad_ref, 'size' => (string) $line->cad_talla, 'quantity' => (int) $line->cad_cantidad])->all();
                $validation = $this->checkoutValidation->validate($countryCode, $fulfillment, $items);
                if (! ($validation['ok'] ?? false)) {
                    throw ValidationException::withMessages(['inventory' => $this->checkoutInventoryError($validation)]);
                }
                foreach ($lines as $line) {
                    $line->forceFill([
                        'cad_estado' => 'DISPONIBLE',
                        'cad_motivo_no_disponible' => null,
                        'cad_seleccionado' => true,
                        'cad_actualizado_en' => now(),
                    ])->save();
                }
                $baseLines = $lines->map(function ($line) use ($cart) {
                    $price = $this->pricing->resolve((int) $cart->car_pais_id, (int) $line->cad_producto_id, (string) $line->cad_ref, (string) $line->cad_talla, now());
                    if (! $price['ok']) {
                        throw ValidationException::withMessages(['price' => $price['message']]);
                    }

                    return [
                        'itemId' => $line->getKey(),
                        'productId' => (int) $line->cad_producto_id,
                        'sku' => (string) $line->cad_ref,
                        'size' => (string) $line->cad_talla,
                        'quantity' => (int) $line->cad_cantidad,
                        'regularPrice' => (float) $price['precio_regular'],
                    ];
                })->values();
                $store = $this->context($cart);
                $resolution = ($this->promotionResolver ?? app(StorefrontPromotionResolver::class))->resolve([
                    'countryId' => (int) $cart->car_pais_id,
                    'checkoutType' => (string) $cart->car_tipo,
                    'storeId' => $cart->car_tipo === 'TIENDA' ? (int) $cart->car_tienda_id : null,
                    'storeName' => $store['storeName'] ?? null,
                    'currencySymbol' => $this->currencySymbol((string) $cart->car_moneda),
                    'lines' => $baseLines->map(fn (array $line) => [
                        'key' => (string) $line['itemId'],
                        'productId' => $line['productId'],
                        'quantity' => $line['quantity'],
                        'unitPrice' => $line['regularPrice'],
                    ])->all(),
                ]);
                $resolvedByItem = collect($resolution['lines'])->keyBy('key');
                $authorized = $baseLines->map(function (array $line) use ($resolvedByItem) {
                    $resolved = $resolvedByItem->get((string) $line['itemId']);
                    $baseLineTotal = (float) ($resolved['baseTotal'] ?? ($line['regularPrice'] * $line['quantity']));
                    $promotionDiscount = (float) ($resolved['discount'] ?? 0);
                    $lineTotal = (float) ($resolved['finalTotal'] ?? $baseLineTotal);
                    $finalPrice = round($lineTotal / $line['quantity'], 4);

                    return [
                        ...$line,
                        'requestedQuantity' => $line['quantity'],
                        'availableQuantity' => $line['quantity'],
                        'ok' => true,
                        'message' => 'Linea autorizada.',
                        'baseLineTotal' => round($baseLineTotal, 2),
                        'discount' => round($promotionDiscount / $line['quantity'], 4),
                        'promotionDiscount' => round($promotionDiscount, 2),
                        'finalPrice' => $finalPrice,
                        'lineTotal' => round($lineTotal, 2),
                        'promotion' => $resolved['promotion'] ?? null,
                    ];
                })->values();
                $baseSubtotal = round($authorized->sum('baseLineTotal'), 2);
                $discount = round($authorized->sum('promotionDiscount'), 2);
                $subtotal = round($authorized->sum('lineTotal'), 2);
                $discountPercentage = $baseSubtotal > 0 ? round($discount * 100 / $baseSubtotal, 2) : 0.0;
                $country = $this->country($countryCode);
                $shipping = ($this->shipping ?? app(StorefrontShippingService::class))->quote($country, (string) $cart->car_tipo, data_get($input, 'delivery.city_id'), number_format($subtotal, 2, '.', ''));
                $shippingAmount = (float) $shipping['shipping_amount'];
                $total = round($subtotal + $shippingAmount, 2);
                $taxes = strtoupper((string) $country->pai_codigo) === 'HN' ? round($total * 15 / 115, 2) : 0.0;
                $destinationHash = $this->destinationHash($cart->car_tipo === 'TIENDA' ? [] : ($input['delivery'] ?? []));
                $previousState = (string) $cart->car_estado;
                $cart->forceFill(['car_estado' => 'CHECKOUT', 'car_checkout_en' => now(), 'car_version' => $cart->car_version + 1, 'car_actualizado_en' => now()])->save();
                $this->pushCancellation()->cancelAllPendingCartDeliveries((int) $cart->getKey(), 'El carrito inicio checkout.');
                $summary = ['service' => $cart->car_tipo, 'store' => $store, 'operationalStoreCode' => (string) $cart->car_tienda_codigo_snapshot, 'lines' => $authorized->all(), 'baseSubtotal' => $baseSubtotal, 'discount' => $discount, 'discountPercentage' => $discountPercentage, 'subtotal' => $subtotal, 'shipping' => $shippingAmount, 'taxes' => $taxes, 'total' => $total, 'currency' => $cart->car_moneda, 'shipping_source' => $shipping['source'], 'shipping_quote' => $shipping, 'destinationHash' => $destinationHash, 'alerts' => [], 'cartVersion' => (int) $cart->car_version];
                $this->audit($cart, null, $visitor, $customer, 'CHECKOUT_STARTED', ['state' => $previousState], ['state' => 'CHECKOUT', 'version' => $cart->car_version, 'destinationHash' => $destinationHash]);
                CustomerEvent::query()->create(['cev_event_uuid' => $input['operation_uuid'] ?? (string) Str::uuid(), 'cev_visitante_id' => $visitor->getKey(), 'cev_usu_id' => $customer?->getKey(), 'cev_pais_id' => $cart->car_pais_id, 'cev_carrito_id' => $cart->getKey(), 'cev_tipo' => 'BEGIN_CHECKOUT', 'cev_valor' => $total, 'cev_moneda' => $cart->car_moneda, 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now(), 'cev_recibido_en' => now(), 'cev_metadata' => ['cartVersion' => $cart->car_version, 'shippingSource' => $shipping['source']]]);

                return ['ok' => true, 'message' => 'Checkout autorizado.', 'cart' => $this->payload($cart)['cart'], 'checkout' => $summary];
            });
        });
    }

    public function previewFulfillment(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $input) {
            $cart = $this->resolveCart($countryCode, $visitor, $customer, true);
            $country = $this->country($countryCode);
            $context = $this->fulfillment->resolve((int) $country->pai_id, (string) $country->pai_codigo, $input);

            return ['current' => $this->context($cart), 'proposed' => $context, 'impact' => $this->evaluateContext($cart, $context), 'cartVersion' => (int) $cart->car_version];
        });
    }

    public function applyFulfillment(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $input) {
            $cart = $this->resolveCart($countryCode, $visitor, $customer, true);
            $this->invalidateCheckout($cart, $visitor, $customer);

            return $this->idempotent($cart, $visitor, $customer, 'FULFILLMENT_CHANGE', $input['operation_uuid'], $input, function () use ($cart, $countryCode, $visitor, $customer, $input) {
                $country = $this->country($countryCode);
                $context = $this->fulfillment->resolve((int) $country->pai_id, (string) $country->pai_codigo, $input);
                $impact = $this->evaluateContext($cart, $context);
                if ($impact['affectedCount'] > 0 && ! ($input['confirm_affected'] ?? false)) {
                    throw ValidationException::withMessages(['confirm_affected' => 'Debe confirmar los cambios del carrito.']);
                } $old = $this->context($cart);
                $cart->forceFill(['car_tipo' => $context['type'], 'car_tienda_id' => $context['storeId'], 'car_tienda_codigo_snapshot' => $context['storeCode'], 'car_inventory_source' => $context['inventorySource']])->save();
                foreach ($impact['items'] as $change) {
                    $line = StorefrontCartItem::query()->find($change['id']);
                    if ($change['status'] !== 'DISPONIBLE') {
                        $line->forceFill(['cad_estado' => $change['status'], 'cad_motivo_no_disponible' => $change['message'], 'cad_seleccionado' => false, 'cad_actualizado_en' => now()])->save();

                        continue;
                    } $line->forceFill($this->lineValues($change['commercial'], $change['quantity']) + ['cad_estado' => 'DISPONIBLE', 'cad_motivo_no_disponible' => null, 'cad_actualizado_en' => now()])->save();
                } $this->touch($cart);
                $this->audit($cart, null, $visitor, $customer, 'FULFILLMENT_CHANGED', $old, $context);

                return $this->payload($cart, $impact['alerts']);
            });
        });
    }

    public function get(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer) {
            $cart = $this->resolveCart($countryCode, $visitor, $customer, true);

            return $this->payload($cart, $cart->car_estado === 'CHECKOUT' ? [] : $this->refreshPrices($cart, $visitor, $customer));
        });
    }

    public function validateForCheckout(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer) {
            $cart = $this->resolveCart($countryCode, $visitor, $customer, true);
            $lines = $cart->items()->lockForUpdate()->get();
            $validation = $this->checkoutValidation->validate($countryCode, [
                'method' => $cart->car_tipo === 'TIENDA' ? 'store_pickup' : 'home_delivery',
                'storeCode' => (string) $cart->car_tienda_codigo_snapshot,
            ], $lines->map(fn ($line) => [
                'key' => (string) $line->getKey(), 'sku' => (string) $line->cad_ref,
                'name' => (string) $line->cad_ref, 'size' => (string) $line->cad_talla,
                'quantity' => (int) $line->cad_cantidad,
            ])->all());
            $validatedById = collect($validation['lines'] ?? [])->keyBy(fn (array $line) => (string) ($line['key'] ?? ''));
            $changed = false;

            foreach ($lines as $line) {
                $result = $validatedById->get((string) $line->getKey());
                $available = $result && ($result['ok'] ?? false);
                $message = (string) ($result['message'] ?? ($validation['message'] ?? 'No se pudo validar la existencia.'));
                $status = $available ? 'DISPONIBLE' : 'SIN_EXISTENCIA';
                $reason = $available ? null : $message;
                if (($line->cad_estado ?? 'DISPONIBLE') === $status
                    && (string) ($line->cad_motivo_no_disponible ?? '') === (string) ($reason ?? '')
                    && (bool) $line->cad_seleccionado) {
                    continue;
                }
                $old = $this->auditLine($line);
                $line->forceFill(['cad_estado' => $status, 'cad_motivo_no_disponible' => $reason, 'cad_seleccionado' => true, 'cad_actualizado_en' => now()])->save();
                $this->audit($cart, $line, $visitor, $customer, 'CHECKOUT_SCOPE_VALIDATED', $old, $this->auditLine($line));
                $changed = true;
            }
            if ($changed) {
                $this->touch($cart);
            }

            return ['ok' => (bool) ($validation['ok'] ?? false), 'message' => $validation['message'] ?? 'Carrito validado.', 'validation' => $validation, ...$this->payload($cart)];
        });
    }

    public function add(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return $this->write('ADD_ITEM', $input['operation_uuid'], $input, $countryCode, $visitor, $customer,
            function (StorefrontCart $cart) use ($input, $visitor, $customer) {
                $commercial = $this->commercial($cart, $input);
                $line = StorefrontCartItem::query()->where('cad_carrito_id', $cart->getKey())
                    ->where('cad_ref', $commercial['sku'])->where('cad_talla', $commercial['size'])->lockForUpdate()->first();
                $old = $line ? $this->auditLine($line) : null;
                $requested = ($line?->cad_cantidad ?? 0) + (int) $input['quantity'];
                $quantity = min($requested, $commercial['available'], 99);
                if ($quantity < 1) {
                    $this->stockError($commercial['available']);
                }

                if ($line) {
                    $line->forceFill($this->lineValues($commercial, $quantity) + ['cad_actualizado_en' => now()])->save();
                } else {
                    $line = StorefrontCartItem::query()->create($this->lineValues($commercial, $quantity) + [
                        'cad_carrito_id' => $cart->getKey(), 'cad_seleccionado' => true,
                        'cad_creado_en' => now(), 'cad_actualizado_en' => now(),
                    ]);
                }
                $this->touch($cart);
                $this->audit($cart, $line, $visitor, $customer, 'ITEM_ADDED', $old, $this->auditLine($line));
                $this->event($cart, $line, $visitor, $customer, 'ADD_TO_CART', $input['operation_uuid']);
                if (! empty($input['recommendation_placement'])) {
                    CustomerEvent::query()->create(['cev_event_uuid' => (string) Str::uuid(), 'cev_visitante_id' => $visitor->getKey(), 'cev_usu_id' => $customer?->getKey(), 'cev_pais_id' => $cart->car_pais_id, 'cev_producto_id' => $line->cad_producto_id, 'cev_carrito_id' => $cart->getKey(), 'cev_tipo' => 'ADD_FROM_RECOMMENDATION', 'cev_cantidad' => (int) $input['quantity'], 'cev_valor' => $line->cad_precio_final_unitario, 'cev_moneda' => $cart->car_moneda, 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now(), 'cev_recibido_en' => now(), 'cev_metadata' => ['placement' => $input['recommendation_placement'], 'recommendation_reason' => $input['recommendation_reason'] ?? null, 'position' => $input['recommendation_position'] ?? null]]);
                }
                $alerts = $quantity < $requested ? [['type' => 'INVENTORY_ADJUSTED', 'message' => "Cantidad limitada a {$quantity}."]] : [];

                return $this->payload($cart, $alerts);
            });
    }

    public function update(string $countryCode, int $itemId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return $this->write('UPDATE_ITEM', $input['operation_uuid'], $input + ['item' => $itemId], $countryCode, $visitor, $customer,
            function (StorefrontCart $cart) use ($itemId, $input, $visitor, $customer) {
                $line = $this->ownedLine($cart, $itemId);
                $old = $this->auditLine($line);
                $action = 'SELECTION_CHANGED';
                if (array_key_exists('quantity', $input)) {
                    $commercial = $this->commercial($cart, [
                        'product_id' => $line->cad_producto_id, 'sku' => $line->cad_ref,
                        'size' => $line->cad_talla, 'quantity' => $input['quantity'],
                    ], $input['inventory_scope'] ?? 'cart');
                    $quantity = min((int) $input['quantity'], $commercial['available'], 99);
                    if ($quantity < 1) {
                        $this->stockError($commercial['available']);
                    }
                    $line->forceFill($this->lineValues($commercial, $quantity));
                    $action = 'QUANTITY_CHANGED';
                }
                if (array_key_exists('selected', $input)) {
                    $line->cad_seleccionado = (bool) $input['selected'];
                }
                $line->cad_actualizado_en = now();
                $line->save();
                $this->touch($cart);
                $this->audit($cart, $line, $visitor, $customer, $action, $old, $this->auditLine($line));

                return $this->payload($cart);
            });
    }

    public function remove(string $countryCode, int $itemId, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return $this->write('REMOVE_ITEM', $input['operation_uuid'], $input + ['item' => $itemId], $countryCode, $visitor, $customer,
            function (StorefrontCart $cart) use ($itemId, $input, $visitor, $customer) {
                $line = $this->ownedLine($cart, $itemId);
                $old = $this->auditLine($line);
                $this->audit($cart, null, $visitor, $customer, 'ITEM_REMOVED', $old, null);
                $this->event($cart, $line, $visitor, $customer, 'REMOVE_FROM_CART', $input['operation_uuid']);
                $line->delete();
                $this->touch($cart);

                return $this->payload($cart);
            });
    }

    public function sync(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return $this->write('SYNC', $input['operation_uuid'], $input, $countryCode, $visitor, $customer,
            function (StorefrontCart $cart) use ($input, $visitor, $customer) {
                $alerts = [];
                foreach ($input['items'] as $item) {
                    try {
                        $commercial = $this->commercial($cart, $item);
                        $quantity = min((int) $item['quantity'], $commercial['available'], 99);
                        if ($quantity < 1) {
                            $alerts[] = ['type' => 'ITEM_REJECTED', 'message' => "{$commercial['sku']} sin inventario."];

                            continue;
                        }
                        $line = StorefrontCartItem::query()->where('cad_carrito_id', $cart->getKey())->where('cad_ref', $commercial['sku'])->where('cad_talla', $commercial['size'])->first();
                        $old = $line ? $this->auditLine($line) : null;
                        if ($line) {
                            $line->forceFill($this->lineValues($commercial, $quantity) + ['cad_actualizado_en' => now()])->save();
                        } else {
                            $line = StorefrontCartItem::query()->create($this->lineValues($commercial, $quantity) + ['cad_carrito_id' => $cart->getKey(), 'cad_seleccionado' => true, 'cad_creado_en' => now(), 'cad_actualizado_en' => now()]);
                            $this->event($cart, $line, $visitor, $customer, 'ADD_TO_CART', (string) Str::uuid());
                        }
                        $this->audit($cart, $line, $visitor, $customer, 'ITEM_ADDED', $old, $this->auditLine($line));
                        if ($quantity < (int) $item['quantity']) {
                            $alerts[] = ['type' => 'INVENTORY_ADJUSTED', 'message' => "{$commercial['sku']} limitado a {$quantity}."];
                        }
                    } catch (ValidationException $e) {
                        $alerts[] = ['type' => 'ITEM_REJECTED', 'message' => collect($e->errors())->flatten()->first()];
                    }
                }
                $this->touch($cart);

                return $this->payload($cart, $alerts);
            });
    }

    public function merge(string $countryCode, StorefrontVisitor $visitor, StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $input) {
            $destination = $this->resolveCart($countryCode, $visitor, $customer, true);

            return $this->idempotent($destination, $visitor, $customer, 'MERGE', $input['operation_uuid'], $input, function () use ($countryCode, $visitor, $customer, $destination) {
                $country = $this->country($countryCode);
                $source = StorefrontCart::query()->where('car_visitante_id', $visitor->getKey())->whereNull('car_usu_id')->where('car_pais_id', $country->pai_id)->where('car_estado', 'ACTIVO')->lockForUpdate()->first();
                if (! $source || $source->getKey() === $destination->getKey()) {
                    return $this->payload($destination);
                }
                foreach ($source->items()->get() as $sourceLine) {
                    $commercial = $this->commercial($destination, ['product_id' => $sourceLine->cad_producto_id, 'sku' => $sourceLine->cad_ref, 'size' => $sourceLine->cad_talla, 'quantity' => $sourceLine->cad_cantidad]);
                    $target = StorefrontCartItem::query()->where('cad_carrito_id', $destination->getKey())->where('cad_ref', $sourceLine->cad_ref)->where('cad_talla', $sourceLine->cad_talla)->first();
                    $qty = min(($target?->cad_cantidad ?? 0) + $sourceLine->cad_cantidad, $commercial['available'], 99);
                    if ($qty < 1) {
                        continue;
                    }
                    if ($target) {
                        $target->forceFill($this->lineValues($commercial, $qty) + ['cad_actualizado_en' => now()])->save();
                    } else {
                        $target = StorefrontCartItem::query()->create($this->lineValues($commercial, $qty) + ['cad_carrito_id' => $destination->getKey(), 'cad_seleccionado' => $sourceLine->cad_seleccionado, 'cad_creado_en' => now(), 'cad_actualizado_en' => now()]);
                    }
                }
                $source->forceFill(['car_estado' => 'MERGED', 'car_actualizado_en' => now()])->save();
                $this->pushCancellation()->cancelAllPendingCartDeliveries((int) $source->getKey(), 'El carrito fue fusionado con otro carrito.');
                $this->audit($destination, null, $visitor, $customer, 'CART_MERGED', ['sourceCartId' => $source->getKey()], ['destinationCartId' => $destination->getKey()]);
                $this->touch($destination);

                return $this->payload($destination);
            });
        });
    }

    private function write(string $type, string $uuid, array $input, string $country, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, callable $callback): array
    {
        return DB::transaction(function () use ($type, $uuid, $input, $country, $visitor, $customer, $callback) {
            $cart = $this->resolveCart($country, $visitor, $customer, true);
            $this->invalidateCheckout($cart, $visitor, $customer);

            return $this->idempotent($cart, $visitor, $customer, $type, $uuid, $input, fn () => $callback($cart));
        });
    }

    private function idempotent(StorefrontCart $cart, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $type, string $uuid, array $input, callable $callback): array
    {
        $hash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $old = DB::table('stj_carrito_operaciones')->where('cao_uuid', $uuid)->lockForUpdate()->first();
        if ($old) {
            return $this->replayOperation($old, $cart, $visitor, $customer, $hash);
        } $result = json_decode(json_encode($callback(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
        try {
            DB::table('stj_carrito_operaciones')->insert(['cao_uuid' => $uuid, 'cao_carrito_id' => $cart->getKey(), 'cao_visitante_id' => $visitor->getKey(), 'cao_usu_id' => $customer?->getKey(), 'cao_tipo' => $type, 'cao_payload_hash' => $hash, 'cao_respuesta' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'cao_creado_en' => now()]);
        } catch (QueryException $e) {
            if (! in_array((string) ($e->errorInfo[1] ?? ''), ['1062', '19'], true)) {
                throw $e;
            }$old = DB::table('stj_carrito_operaciones')->where('cao_uuid', $uuid)->firstOrFail();

            return $this->replayOperation($old, $cart, $visitor, $customer, $hash);
        }

        return $result;
    }

    private function replayOperation(object $old, StorefrontCart $cart, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $hash): array
    {
        if ((int) $old->cao_carrito_id !== $cart->getKey() || (int) $old->cao_visitante_id !== $visitor->getKey() || ($old->cao_usu_id === null ? null : (int) $old->cao_usu_id) !== $customer?->getKey() || ! hash_equals($old->cao_payload_hash, $hash)) {
            throw new CartOperationConflict('operation_uuid ya fue utilizado por otra identidad o con otro contenido.');
        }

        return json_decode($old->cao_respuesta, true);
    }

    private function resolveCart(string $code, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, bool $create, bool $hydrateContext = true): ?StorefrontCart
    {
        $country = $this->country($code);
        $q = StorefrontCart::query()->where('car_pais_id', $country->pai_id)->whereIn('car_estado', ['ACTIVO', 'CHECKOUT']);
        $customer ? $q->where('car_usu_id', $customer->getKey()) : $q->whereNull('car_usu_id')->where('car_visitante_id', $visitor->getKey());
        $cart = $q->lockForUpdate()->first();
        if ($hydrateContext && $cart && ! $cart->car_tienda_codigo_snapshot) {
            $context = $this->fulfillment->resolve((int) $country->pai_id, (string) $country->pai_codigo, ['fulfillment_type' => 'DOMICILIO']);
            $cart->forceFill(['car_tipo' => $context['type'], 'car_tienda_id' => $context['storeId'], 'car_tienda_codigo_snapshot' => $context['storeCode'], 'car_inventory_source' => $context['inventorySource']])->save();
        }
        if ($cart || ! $create) {
            return $cart;
        } $now = now();
        $context = $this->fulfillment->resolve((int) $country->pai_id, (string) $country->pai_codigo, ['fulfillment_type' => 'DOMICILIO']);
        $cart = StorefrontCart::query()->create(['car_uuid' => (string) Str::uuid(), 'car_visitante_id' => $visitor->getKey(), 'car_usu_id' => $customer?->getKey(), 'car_pais_id' => $country->pai_id, 'car_tipo' => 'DOMICILIO', 'car_tienda_id' => $context['storeId'], 'car_tienda_codigo_snapshot' => $context['storeCode'], 'car_inventory_source' => $context['inventorySource'], 'car_estado' => 'ACTIVO', 'car_origen' => 'WEB', 'car_moneda' => $this->currency(strtolower($country->pai_codigo)), 'car_version' => 1, 'car_ultima_actividad_en' => $now, 'car_expira_en' => $now->copy()->addDays(30), 'car_creado_en' => $now, 'car_actualizado_en' => $now]);
        $this->audit($cart, null, $visitor, $customer, 'CART_CREATED', null, ['state' => 'ACTIVO']);

        return $cart;
    }

    private function commercial(StorefrontCart $cart, array $input, string $inventoryScope = 'cart'): array
    {
        $price = $this->pricing->resolve((int) $cart->car_pais_id, (int) $input['product_id'], trim($input['sku']), trim($input['size']), now());
        if (! $price['ok']) {
            throw ValidationException::withMessages(['price' => $price['message']]);
        } $country = DB::table('stj_paises')->where('pai_id', $cart->car_pais_id)->value('pai_codigo');
        $slug = Str::slug($price['name']).'-'.$price['productId'];
        $availability = $this->availability->forCountryAndSlug(strtolower($country), $slug, (string) $cart->car_tienda_codigo_snapshot, $inventoryScope);
        $sizeData = collect($availability['sizes'] ?? [])->firstWhere('size', $price['size']);
        $available = (int) ($sizeData['quantityInActiveStore'] ?? 0);
        if ($available < 1) {
            $this->stockError($available);
        }

        return ['productId' => $price['productId'], 'sku' => $price['sku'], 'name' => $price['name'], 'size' => $price['size'], 'available' => $available, 'regular' => $price['precio_regular'], 'discount' => $price['descuento'], 'final' => $price['precio_final'], 'promotion' => $price['promocion'], 'inventorySource' => $availability['inventorySource'] ?? null];
    }

    private function refreshPrices(StorefrontCart $cart, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        $alerts = [];
        foreach ($cart->items()->lockForUpdate()->get() as $line) {
            $old = $this->auditLine($line);
            try {
                $commercial = $this->commercial($cart, ['product_id' => $line->cad_producto_id, 'sku' => $line->cad_ref, 'size' => $line->cad_talla, 'quantity' => $line->cad_cantidad]);
                $quantity = min((int) $line->cad_cantidad, $commercial['available'], 99);
                $priceChanged = $this->money($line->cad_precio_unitario) !== $commercial['regular'] || $this->money($line->cad_descuento_unitario) !== $commercial['discount'] || $this->money($line->cad_precio_final_unitario) !== $commercial['final'];
                $stateChanged = ($line->cad_estado ?? 'DISPONIBLE') !== 'DISPONIBLE' || (int) $line->cad_cantidad !== $quantity;
                if (! $priceChanged && ! $stateChanged) {
                    continue;
                }
                $line->forceFill($this->lineValues($commercial, $quantity) + ['cad_actualizado_en' => now()])->save();
                $action = $priceChanged ? 'PRICE_CHANGED' : 'LINE_REVALIDATED';
                $message = $priceChanged ? 'El precio del producto cambio.' : 'La linea fue revalidada para el contexto activo.';
            } catch (ValidationException $e) {
                $message = (string) collect($e->errors())->flatten()->first();
                $status = str_contains(strtolower($message), 'inactivo') ? 'PRODUCTO_INACTIVO' : (str_contains(strtolower($message), 'precio') ? 'PRECIO_NO_DISPONIBLE' : (str_contains(strtolower($message), 'talla') ? 'TALLA_NO_DISPONIBLE' : 'SIN_EXISTENCIA'));
                if (($line->cad_estado ?? 'DISPONIBLE') === $status && ! $line->cad_seleccionado) {
                    continue;
                }
                $line->forceFill(['cad_estado' => $status, 'cad_motivo_no_disponible' => $message, 'cad_seleccionado' => false, 'cad_actualizado_en' => now()])->save();
                $action = 'LINE_REVALIDATED';
            }
            $this->touch($cart);
            $this->audit($cart, $line, $visitor, $customer, $action, $old, $this->auditLine($line));
            $alerts[] = ['type' => $action === 'PRICE_CHANGED' ? 'PRICE_CHANGED' : ($line->cad_estado ?? 'LINE_REVALIDATED'), 'itemId' => $line->getKey(), 'message' => $message];
        }

        return $alerts;
    }

    private function money(mixed $value): string
    {
        $parts = explode('.', trim((string) $value), 2);

        return ($parts[0] ?: '0').'.'.str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    private function context(StorefrontCart $cart): array
    {
        $store = DB::table('stj_tiendas')->where('tie_id', $cart->car_tienda_id)->where('tie_pais', $cart->car_pais_id)->first();

        return ['type' => $cart->car_tipo, 'storeId' => $cart->car_tienda_id ? (int) $cart->car_tienda_id : null, 'storeCode' => (string) $cart->car_tienda_codigo_snapshot, 'storeName' => $cart->car_tipo === 'DOMICILIO' ? 'Domicilio' : data_get($store, 'tie_nombre'), 'storeAddress' => data_get($store, 'tie_direccion'), 'storePhone' => data_get($store, 'tie_telefono'), 'storeEmail' => data_get($store, 'tie_correo'), 'storeSchedule' => data_get($store, 'tie_horario'), 'inventorySource' => $cart->car_inventory_source];
    }

    private function evaluateContext(StorefrontCart $cart, array $context): array
    {
        $current = $this->context($cart);
        if ($current['type'] === $context['type'] && (string) $current['storeCode'] === (string) $context['storeCode']) {
            return ['affectedCount' => 0, 'items' => [], 'alerts' => [], 'inventorySource' => null];
        }

        $virtual = clone $cart;
        $virtual->car_tipo = $context['type'];
        $virtual->car_tienda_id = $context['storeId'];
        $virtual->car_tienda_codigo_snapshot = $context['storeCode'];
        $virtual->car_inventory_source = $context['inventorySource'];
        $items = [];
        $alerts = [];
        $affected = 0;
        foreach ($cart->items()->get() as $line) {
            try {
                $commercial = $this->commercial($virtual, ['product_id' => $line->cad_producto_id, 'sku' => $line->cad_ref, 'size' => $line->cad_talla, 'quantity' => $line->cad_cantidad], 'cart_store_change');
                $quantity = min((int) $line->cad_cantidad, $commercial['available'], 99);
                $priceChanged = $this->money($line->cad_precio_final_unitario) !== $commercial['final'];
                $changed = $quantity !== (int) $line->cad_cantidad || $priceChanged;
                $items[] = ['id' => $line->getKey(), 'sku' => $line->cad_ref, 'size' => $line->cad_talla, 'status' => 'DISPONIBLE', 'availability' => true, 'requestedQuantity' => (int) $line->cad_cantidad, 'availableQuantity' => $commercial['available'], 'quantity' => $quantity, 'previousQuantity' => (int) $line->cad_cantidad, 'priceChanged' => $priceChanged, 'promotionChanged' => false, 'commercial' => $commercial, 'message' => $changed ? 'Cantidad o precio sera actualizado.' : 'Sin cambios.'];
            } catch (ValidationException $e) {
                if (isset($e->errors()['inventory_rule'])) {
                    throw $e;
                }
                $affected++;
                $message = (string) collect($e->errors())->flatten()->first();
                $status = str_contains(strtolower($message), 'precio') ? 'PRECIO_NO_DISPONIBLE' : (str_contains(strtolower($message), 'talla') ? 'TALLA_NO_DISPONIBLE' : 'SIN_EXISTENCIA');
                $items[] = ['id' => $line->getKey(), 'sku' => $line->cad_ref, 'size' => $line->cad_talla, 'status' => $status, 'availability' => false, 'requestedQuantity' => (int) $line->cad_cantidad, 'availableQuantity' => 0, 'quantity' => (int) $line->cad_cantidad, 'previousQuantity' => (int) $line->cad_cantidad, 'priceChanged' => false, 'promotionChanged' => false, 'commercial' => null, 'message' => $message];
                $alerts[] = ['type' => $status, 'itemId' => $line->getKey(), 'message' => $message];
            }
        }

        $availableItems = collect($items)->where('availability', true);
        if ($availableItems->isNotEmpty()) {
            $resolver = $this->promotionResolver ?? app(StorefrontPromotionResolver::class);
            $promotionLines = fn (bool $proposed) => $availableItems->map(fn (array $item) => [
                'key' => (string) $item['id'],
                'productId' => (int) $item['commercial']['productId'],
                'quantity' => $proposed ? (int) $item['quantity'] : (int) $item['requestedQuantity'],
                'unitPrice' => $proposed ? (float) $item['commercial']['regular'] : (float) $cart->items->firstWhere('cad_id', $item['id'])->cad_precio_unitario,
            ])->values()->all();
            $resolvePromotions = fn (array $fulfillment, bool $proposed) => collect($resolver->resolve([
                'countryId' => (int) $cart->car_pais_id,
                'checkoutType' => (string) $fulfillment['type'],
                'storeId' => $fulfillment['type'] === 'TIENDA' ? (int) $fulfillment['storeId'] : null,
                'storeName' => $fulfillment['storeName'] ?? null,
                'currencySymbol' => $this->currencySymbol((string) $cart->car_moneda),
                'lines' => $promotionLines($proposed),
            ])['lines'])->keyBy('key');
            $currentPromotions = $resolvePromotions($current, false);
            $proposedPromotions = $resolvePromotions($context, true);
            foreach ($items as &$item) {
                if (! $item['availability']) {
                    continue;
                }
                $before = $currentPromotions->get((string) $item['id']);
                $after = $proposedPromotions->get((string) $item['id']);
                $item['promotionChanged'] = json_encode($before['promotion'] ?? null) !== json_encode($after['promotion'] ?? null)
                    || $this->money($before['discount'] ?? 0) !== $this->money($after['discount'] ?? 0);
                if ($item['promotionChanged']) {
                    $item['message'] = 'Cantidad, precio o promocion sera actualizado.';
                }
            }
            unset($item);
        }
        $affected = collect($items)->filter(fn (array $item) => ! $item['availability']
            || $item['quantity'] !== $item['requestedQuantity']
            || $item['priceChanged']
            || $item['promotionChanged'])->count();

        return ['affectedCount' => $affected, 'items' => $items, 'alerts' => $alerts, 'inventorySource' => data_get($items, '0.commercial.inventorySource')];
    }

    private function lineValues(array $c, int $q): array
    {
        return ['cad_producto_id' => $c['productId'], 'cad_ref' => $c['sku'], 'cad_talla' => $c['size'], 'cad_cantidad' => $q, 'cad_precio_unitario' => $c['regular'], 'cad_descuento_unitario' => $c['discount'], 'cad_precio_final_unitario' => $c['final'], 'cad_promocion' => $c['promotion'], 'cad_estado' => 'DISPONIBLE', 'cad_motivo_no_disponible' => null];
    }

    private function checkoutInventoryError(array $validation): string
    {
        $failures = collect($validation['lines'] ?? [])
            ->filter(fn (array $line) => ! ($line['ok'] ?? false))
            ->map(fn (array $line) => sprintf(
                '%s, talla %s: solicitadas %d, disponibles %d',
                (string) ($line['name'] ?? $line['sku'] ?? 'Producto'),
                (string) ($line['size'] ?? ''),
                (int) ($line['requestedQuantity'] ?? 0),
                (int) ($line['availableQuantity'] ?? 0),
            ))
            ->values();

        $message = (string) ($validation['message'] ?? 'No se pudo validar el inventario de checkout.');

        return $failures->isEmpty() ? $message : $message.' '.$failures->implode('; ').'.';
    }

    private function ownedLine(StorefrontCart $cart, int $id): StorefrontCartItem
    {
        $line = StorefrontCartItem::query()->where('cad_id', $id)->where('cad_carrito_id', $cart->getKey())->lockForUpdate()->first();
        if (! $line) {
            throw ValidationException::withMessages(['item' => 'La linea no pertenece al carrito autorizado.']);
        }

        return $line;
    }

    private function touch(StorefrontCart $cart): void
    {
        $cart->forceFill(['car_version' => $cart->car_version + 1, 'car_ultima_actividad_en' => now(), 'car_expira_en' => now()->addDays(30), 'car_actualizado_en' => now()])->save();
        $this->pushCancellation()->cancelStaleCartDeliveries((int) $cart->getKey(), (int) $cart->car_version, 'El carrito cambio despues de crear la entrega.');
    }

    private function invalidateCheckout(StorefrontCart $cart, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): void
    {
        if ($cart->car_estado !== 'CHECKOUT') {
            return;
        }
        $cart->forceFill(['car_estado' => 'ACTIVO', 'car_checkout_en' => null, 'car_version' => $cart->car_version + 1, 'car_actualizado_en' => now()])->save();
        $this->pushCancellation()->cancelStaleCartDeliveries((int) $cart->getKey(), (int) $cart->car_version, 'El checkout fue invalidado y el carrito cambio.');
        $this->audit($cart, null, $visitor, $customer, 'CHECKOUT_INVALIDATED', ['state' => 'CHECKOUT'], ['state' => 'ACTIVO']);
    }

    private function pushCancellation(): WebPushDeliveryCancellationService
    {
        return $this->pushDeliveryCancellation ??= app(WebPushDeliveryCancellationService::class);
    }

    private function audit(StorefrontCart $cart, ?StorefrontCartItem $line, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $action, ?array $old, ?array $new): void
    {
        StorefrontCartAudit::query()->create(['cau_carrito_id' => $cart->getKey(), 'cau_detalle_id' => $line?->getKey(), 'cau_visitante_id' => $visitor->getKey(), 'cau_usu_id' => $customer?->getKey(), 'cau_accion' => $action, 'cau_origen' => 'WEB', 'cau_cantidad_anterior' => $old['quantity'] ?? null, 'cau_cantidad_nueva' => $new['quantity'] ?? null, 'cau_datos_anteriores' => $old, 'cau_datos_nuevos' => $new, 'cau_ocurrido_en' => now()]);
    }

    private function event(StorefrontCart $cart, StorefrontCartItem $line, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $type, string $uuid): void
    {
        CustomerEvent::query()->create(['cev_event_uuid' => $uuid, 'cev_visitante_id' => $visitor->getKey(), 'cev_usu_id' => $customer?->getKey(), 'cev_pais_id' => $cart->car_pais_id, 'cev_producto_id' => $line->cad_producto_id, 'cev_carrito_id' => $cart->getKey(), 'cev_tipo' => $type, 'cev_cantidad' => $line->cad_cantidad, 'cev_valor' => $line->cad_precio_final_unitario, 'cev_moneda' => $cart->car_moneda, 'cev_origen' => 'WEB', 'cev_ocurrido_en' => now(), 'cev_recibido_en' => now(), 'cev_metadata' => ['sku' => $line->cad_ref, 'size' => $line->cad_talla]]);
    }

    private function auditLine(StorefrontCartItem $l): array
    {
        return ['sku' => $l->cad_ref, 'size' => $l->cad_talla, 'quantity' => (int) $l->cad_cantidad, 'selected' => (bool) $l->cad_seleccionado, 'regular' => (float) $l->cad_precio_unitario, 'discount' => (float) $l->cad_descuento_unitario, 'final' => (float) $l->cad_precio_final_unitario];
    }

    private function payload(StorefrontCart $cart, array $alerts = []): array
    {
        $cart->load('items');
        $products = DB::table('stj_productos')->whereIn('pro_id', $cart->items->pluck('cad_producto_id'))->get(['pro_id', 'pro_nombre', 'pro_thumbs'])->keyBy('pro_id');
        $countryCode = strtolower((string) DB::table('stj_paises')->where('pai_id', $cart->car_pais_id)->value('pai_codigo'));
        $fulfillment = $this->context($cart);
        $eligibleItems = $cart->items
            ->filter(fn ($item) => ($item->cad_estado ?? 'DISPONIBLE') === 'DISPONIBLE' && (bool) $item->cad_seleccionado);
        $resolvedLines = collect();

        if ($eligibleItems->isNotEmpty()) {
            $resolution = ($this->promotionResolver ?? app(StorefrontPromotionResolver::class))->resolve([
                'countryId' => (int) $cart->car_pais_id,
                'checkoutType' => (string) $cart->car_tipo,
                'storeId' => $cart->car_tipo === 'TIENDA' ? (int) $cart->car_tienda_id : null,
                'storeName' => $fulfillment['storeName'] ?? null,
                'currencySymbol' => $this->currencySymbol((string) $cart->car_moneda),
                'lines' => $eligibleItems->map(fn ($item) => [
                    'key' => (string) $item->getKey(),
                    'productId' => (int) $item->cad_producto_id,
                    'quantity' => (int) $item->cad_cantidad,
                    'unitPrice' => (float) $item->cad_precio_unitario,
                ])->values()->all(),
            ]);
            $resolvedLines = collect($resolution['lines'])->keyBy('key');
        }

        $items = $cart->items->map(function ($item) use ($countryCode, $products, $resolvedLines, $cart) {
            $quantity = (int) $item->cad_cantidad;
            $selectedAndAvailable = ($item->cad_estado ?? 'DISPONIBLE') === 'DISPONIBLE' && (bool) $item->cad_seleccionado;
            $resolved = $resolvedLines->get((string) $item->getKey());
            $regularPrice = (float) $item->cad_precio_unitario;
            $baseTotal = (float) ($resolved['baseTotal'] ?? ($regularPrice * $quantity));
            $promotionDiscount = $selectedAndAvailable ? (float) ($resolved['discount'] ?? 0) : 0.0;
            $lineSubtotal = (float) ($resolved['finalTotal'] ?? $baseTotal);
            $effectiveUnitPrice = $quantity > 0 ? round($lineSubtotal / $quantity, 4) : $regularPrice;
            $effectiveUnitDiscount = $quantity > 0 ? round($promotionDiscount / $quantity, 4) : 0.0;

            return [
                'id' => $item->getKey(),
                'key' => $countryCode.':'.$item->cad_ref.':'.$item->cad_talla,
                'countryCode' => $countryCode,
                'productId' => (int) $item->cad_producto_id,
                'name' => $products[$item->cad_producto_id]->pro_nombre ?? $item->cad_ref,
                'imageUrl' => StorefrontImageUrl::image($products[$item->cad_producto_id]->pro_thumbs ?? null, 'p100'),
                'sku' => $item->cad_ref,
                'size' => $item->cad_talla,
                'quantity' => $quantity,
                'selected' => (bool) $item->cad_seleccionado,
                'includedInTotals' => $selectedAndAvailable,
                'status' => $item->cad_estado ?? 'DISPONIBLE',
                'unavailableReason' => $item->cad_motivo_no_disponible,
                'price' => $effectiveUnitPrice,
                'regularPrice' => $regularPrice,
                'discount' => $effectiveUnitDiscount,
                'promotionDiscount' => round($promotionDiscount, 2),
                'finalPrice' => $effectiveUnitPrice,
                'baseSubtotal' => round($baseTotal, 2),
                'lineSubtotal' => round($lineSubtotal, 2),
                'promotion' => $resolved['promotion'] ?? null,
                'currency' => $cart->car_moneda,
            ];
        })->values();
        $includedItems = $items->where('includedInTotals', true);
        $baseSubtotal = round($includedItems->sum('baseSubtotal'), 2);
        $discount = round($includedItems->sum('promotionDiscount'), 2);
        $total = round($includedItems->sum('lineSubtotal'), 2);

        return ['cart' => ['id' => $cart->getKey(), 'uuid' => $cart->car_uuid, 'state' => $cart->car_estado, 'type' => $cart->car_tipo, 'fulfillment' => $fulfillment, 'version' => (int) $cart->car_version, 'currency' => $cart->car_moneda, 'items' => $items->all(), 'totals' => ['baseSubtotal' => $baseSubtotal, 'subtotal' => $total, 'discount' => $discount, 'total' => $total, 'currency' => $cart->car_moneda], 'alerts' => $alerts, 'updatedAt' => $cart->car_actualizado_en]];
    }

    private function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'GTQ' => 'Q',
            'CRC' => '₡',
            'HNL' => 'L',
            'DOP' => 'RD$',
            default => '$',
        };
    }

    private function country(string $code): object
    {
        $c = DB::table('stj_paises')->where('pai_codigo', strtoupper($code))->first(['pai_id', 'pai_id_world', 'pai_codigo']);
        if (! $c) {
            throw ValidationException::withMessages(['country' => 'Pais no soportado.']);
        }

        return $c;
    }

    private function currency(string $c): string
    {
        return ['gt' => 'GTQ', 'cr' => 'CRC', 'pa' => 'USD', 'do' => 'DOP', 'hn' => 'HNL'][$c] ?? 'USD';
    }

    private function destinationHash(array $delivery): string
    {
        $normalized = ['city_id' => (int) ($delivery['city_id'] ?? 0), 'state_id' => (int) ($delivery['state_id'] ?? 0), 'address' => mb_strtolower(trim((string) ($delivery['addressLine1'] ?? ''))), 'reference' => mb_strtolower(trim((string) ($delivery['reference'] ?? '')))];

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function stockError(int $available): never
    {
        throw ValidationException::withMessages(['quantity' => "Inventario insuficiente. Disponible: {$available}."]);
    }
}
