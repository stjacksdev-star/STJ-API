<?php

namespace App\Services;

use App\Exceptions\CartOperationConflict;
use App\Models\CustomerEvent;
use App\Models\StorefrontCart;
use App\Models\StorefrontCartAudit;
use App\Models\StorefrontCartItem;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontCartService
{
    public function __construct(private ProductDetailAvailabilityService $availability, private StorefrontProductPricingService $pricing) {}

    public function get(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer) {
            $cart = $this->resolveCart($countryCode, $visitor, $customer, true);

            return $this->payload($cart, $this->refreshPrices($cart, $visitor, $customer));
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
                    ]);
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

    private function resolveCart(string $code, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, bool $create): StorefrontCart
    {
        $country = $this->country($code);
        $q = StorefrontCart::query()->where('car_pais_id', $country->pai_id)->where('car_estado', 'ACTIVO');
        $customer ? $q->where('car_usu_id', $customer->getKey()) : $q->whereNull('car_usu_id')->where('car_visitante_id', $visitor->getKey());
        $cart = $q->lockForUpdate()->first();
        if ($cart || ! $create) {
            return $cart;
        } $now = now();
        $cart = StorefrontCart::query()->create(['car_uuid' => (string) Str::uuid(), 'car_visitante_id' => $visitor->getKey(), 'car_usu_id' => $customer?->getKey(), 'car_pais_id' => $country->pai_id, 'car_tipo' => 'DOMICILIO', 'car_estado' => 'ACTIVO', 'car_origen' => 'WEB', 'car_moneda' => $this->currency(strtolower($country->pai_codigo)), 'car_version' => 1, 'car_ultima_actividad_en' => $now, 'car_expira_en' => $now->copy()->addDays(30), 'car_creado_en' => $now, 'car_actualizado_en' => $now]);
        $this->audit($cart, null, $visitor, $customer, 'CART_CREATED', null, ['state' => 'ACTIVO']);

        return $cart;
    }

    private function commercial(StorefrontCart $cart, array $input): array
    {
        $price = $this->pricing->resolve((int) $cart->car_pais_id, (int) $input['product_id'], trim($input['sku']), trim($input['size']), now());
        if (! $price['ok']) {
            throw ValidationException::withMessages(['price' => $price['message']]);
        } $country = DB::table('stj_paises')->where('pai_id', $cart->car_pais_id)->value('pai_codigo');
        $slug = Str::slug($price['name']).'-'.$price['productId'];
        $availability = $this->availability->forCountryAndSlug(strtolower($country), $slug, config('inventory.default_store_by_country.'.strtolower($country)));
        $sizeData = collect($availability['sizes'] ?? [])->firstWhere('size', $price['size']);
        $available = (int) ($sizeData['quantityInActiveStore'] ?? 0);
        if ($available < 1) {
            $this->stockError($available);
        }

return ['productId' => $price['productId'], 'sku' => $price['sku'], 'name' => $price['name'], 'size' => $price['size'], 'available' => $available, 'regular' => $price['precio_regular'], 'discount' => $price['descuento'], 'final' => $price['precio_final'], 'promotion' => $price['promocion']];
    }

    private function refreshPrices(StorefrontCart $cart, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): array
    {
        $alerts = [];
        foreach ($cart->items()->lockForUpdate()->get() as $line) {
            $price = $this->pricing->resolve((int) $cart->car_pais_id, (int) $line->cad_producto_id, (string) $line->cad_ref, (string) $line->cad_talla, now());
            if (! $price['ok']) {
                $alerts[] = ['type' => $price['reason'], 'itemId' => $line->getKey(), 'message' => $price['message']];

                continue;
            } $old = $this->auditLine($line);
            if ($this->money($line->cad_precio_unitario) === $price['precio_regular'] && $this->money($line->cad_descuento_unitario) === $price['descuento'] && $this->money($line->cad_precio_final_unitario) === $price['precio_final']) {
                continue;
            } $line->forceFill(['cad_precio_unitario' => $price['precio_regular'], 'cad_descuento_unitario' => $price['descuento'], 'cad_precio_final_unitario' => $price['precio_final'], 'cad_promocion' => $price['promocion'], 'cad_actualizado_en' => now()])->save();
            $this->touch($cart);
            $this->audit($cart, $line, $visitor, $customer, 'PRICE_CHANGED', $old, $this->auditLine($line));
            $alerts[] = ['type' => 'PRICE_CHANGED', 'itemId' => $line->getKey(), 'message' => 'El precio del producto cambio.'];
        }

return $alerts;
    }

    private function money(mixed $value): string
    {
        $parts = explode('.', trim((string) $value), 2);

        return ($parts[0] ?: '0').'.'.str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    private function lineValues(array $c, int $q): array
    {
        return ['cad_producto_id' => $c['productId'], 'cad_ref' => $c['sku'], 'cad_talla' => $c['size'], 'cad_cantidad' => $q, 'cad_precio_unitario' => $c['regular'], 'cad_descuento_unitario' => $c['discount'], 'cad_precio_final_unitario' => $c['final'], 'cad_promocion' => $c['promotion']];
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
        $products = DB::table('stj_productos')->whereIn('pro_id', $cart->items->pluck('cad_producto_id'))->pluck('pro_nombre', 'pro_id');
        $items = $cart->items->map(fn ($i) => ['id' => $i->getKey(), 'key' => strtolower(DB::table('stj_paises')->where('pai_id', $cart->car_pais_id)->value('pai_codigo')).':'.$i->cad_ref.':'.$i->cad_talla, 'countryCode' => strtolower(DB::table('stj_paises')->where('pai_id', $cart->car_pais_id)->value('pai_codigo')), 'productId' => (int) $i->cad_producto_id, 'name' => $products[$i->cad_producto_id] ?? $i->cad_ref, 'sku' => $i->cad_ref, 'size' => $i->cad_talla, 'quantity' => (int) $i->cad_cantidad, 'selected' => (bool) $i->cad_seleccionado, 'price' => (float) $i->cad_precio_final_unitario, 'regularPrice' => (float) $i->cad_precio_unitario, 'discount' => (float) $i->cad_descuento_unitario, 'finalPrice' => (float) $i->cad_precio_final_unitario, 'lineSubtotal' => round($i->cad_precio_final_unitario * $i->cad_cantidad, 2), 'currency' => $cart->car_moneda])->values();
        $subtotal = round($items->sum('lineSubtotal'), 2);

        return ['cart' => ['id' => $cart->getKey(), 'uuid' => $cart->car_uuid, 'state' => $cart->car_estado, 'type' => $cart->car_tipo, 'version' => (int) $cart->car_version, 'currency' => $cart->car_moneda, 'items' => $items->all(), 'totals' => ['subtotal' => $subtotal, 'discount' => round($items->sum(fn ($i) => $i['discount'] * $i['quantity']), 2), 'total' => $subtotal, 'currency' => $cart->car_moneda], 'alerts' => $alerts, 'updatedAt' => $cart->car_actualizado_en]];
    }

    private function country(string $code): object
    {
        $c = DB::table('stj_paises')->where('pai_codigo',strtoupper($code))->first(['pai_id', 'pai_codigo']);
        if (! $c) {
            throw ValidationException::withMessages(['country' => 'Pais no soportado.']);
        }

return $c;
    }

    private function currency(string $c): string
    {
        return ['gt' => 'GTQ', 'cr' => 'CRC', 'pa' => 'USD', 'do' => 'DOP', 'hn' => 'HNL'][$c] ?? 'USD';
    }

    private function stockError(int $available): never
    {
        throw ValidationException::withMessages(['quantity' => "Inventario insuficiente. Disponible: {$available}."]);
    }
}
