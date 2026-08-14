<?php

namespace App\Services;

use App\Exceptions\CartOperationConflict;
use App\Support\CouponProductScope;
use App\Models\StorefrontCart;
use App\Models\StorefrontCartCoupon;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StorefrontCartCouponService
{
    public function __construct(
        private StorefrontCouponResolver $coupons,
        private StorefrontPromotionResolver $promotions,
    ) {}

    /** @return array<string, mixed> */
    public function add(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $input) {
            $cart = $this->cart($countryCode, $visitor, $customer);
            $code = mb_strtoupper(trim((string) $input['code']));
            $coupon = DB::table('stj_cupones as c')
                ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
                ->whereRaw('UPPER(TRIM(c.cup_codigo)) = ?', [$code])
                ->where('h.che_pais', $cart->car_pais_id)
                ->where('h.che_estado', 'ACTIVO')
                ->where('c.cup_estado', 'ACTIVO')
                ->whereIn('h.che_aplica', ['TODO', 'WEB'])
                ->orderByDesc('c.cup_id')
                ->first(['c.cup_id', 'c.cup_codigo', 'h.che_multiple', 'h.che_generico']);

            if (! $coupon) {
                throw ValidationException::withMessages(['code' => 'El cupón no existe o no aplica para este país.']);
            }
            if (($coupon->che_generico ?? 'NO') !== 'SI' && ($coupon->che_multiple ?? 'NO') !== 'SI' && DB::table('stj_pedido_cupones_aplicados')
                ->where('pca_cupon_id', $coupon->cup_id)
                ->where('pca_estado', 'CONSUMIDO')
                ->exists()) {
                throw ValidationException::withMessages(['code' => 'El cupón ya fue utilizado en un pedido aprobado.']);
            }

            $operation = StorefrontCartCoupon::query()->where('ccu_operation_uuid', $input['operation_uuid'])->first();
            if ($operation) {
                if ((int) $operation->ccu_carrito_id !== (int) $cart->getKey() || (int) $operation->ccu_cupon_id !== (int) $coupon->cup_id) {
                    throw new CartOperationConflict('operation_uuid ya fue utilizado para otra operación de cupón.');
                }

                return $this->revalidate($cart, (string) ($input['email'] ?? ''));
            }

            $active = StorefrontCartCoupon::query()
                ->where('ccu_carrito_id', $cart->getKey())
                ->where('ccu_cupon_id', $coupon->cup_id)
                ->whereIn('ccu_estado', ['AGREGADO', 'APLICADO', 'NO_APLICABLE'])
                ->lockForUpdate()
                ->first();

            if (! $active) {
                $now = now();
                StorefrontCartCoupon::query()->create([
                    'ccu_carrito_id' => $cart->getKey(),
                    'ccu_cupon_id' => $coupon->cup_id,
                    'ccu_codigo' => $coupon->cup_codigo,
                    'ccu_estado' => 'AGREGADO',
                    'ccu_carrito_version' => $cart->car_version,
                    'ccu_correo_snapshot' => $this->email((string) ($input['email'] ?? '')),
                    'ccu_pais_id' => $cart->car_pais_id,
                    'ccu_checkout_snapshot' => $cart->car_tipo,
                    'ccu_operation_uuid' => $input['operation_uuid'],
                    'ccu_agregado_en' => $now,
                    'ccu_creado_en' => $now,
                    'ccu_actualizado_en' => $now,
                ]);
            }

            return $this->revalidate($cart, (string) ($input['email'] ?? ''));
        });
    }

    /** @return array<string, mixed> */
    public function remove(string $countryCode, int $application, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, array $input): array
    {
        return DB::transaction(function () use ($countryCode, $application, $visitor, $customer, $input) {
            $cart = $this->cart($countryCode, $visitor, $customer);
            $row = StorefrontCartCoupon::query()
                ->whereKey($application)
                ->where('ccu_carrito_id', $cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->ccu_estado !== 'CONSUMIDO') {
                $row->forceFill([
                    'ccu_estado' => 'ELIMINADO',
                    'ccu_razon_codigo' => 'ELIMINADO_CLIENTE',
                    'ccu_razon_mensaje' => 'El cliente eliminó el cupón.',
                    'ccu_operation_uuid' => $input['operation_uuid'],
                    'ccu_descuento_productos' => 0,
                    'ccu_descuento_envio' => 0,
                    'ccu_eliminado_en' => now(),
                    'ccu_actualizado_en' => now(),
                ])->save();
            }

            return $this->revalidate($cart, (string) ($input['email'] ?? ''));
        });
    }

    /** @return array<string, mixed> */
    public function revalidateForIdentity(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer, string $email = ''): array
    {
        return DB::transaction(function () use ($countryCode, $visitor, $customer, $email) {
            return $this->revalidate($this->cart($countryCode, $visitor, $customer), $email);
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function available(string $countryCode, ?StorefrontCustomer $customer, ?string $email = null): array
    {
        $country = DB::table('stj_paises')->whereRaw('LOWER(pai_codigo) = ?', [strtolower($countryCode)])->first(['pai_id', 'pai_codigo']);
        if (! $country) {
            throw ValidationException::withMessages(['country' => 'El país no existe.']);
        }
        // Personal coupon codes are private account data. An arbitrary email
        // supplied by a guest must never be enough to enumerate them.
        $email = $this->email((string) ($customer?->usu_correo ?? ''));
        $now = now();
        $rows = DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'h.che_genero')
            ->leftJoin('stj_coleccion as collection', 'collection.col_id', '=', 'h.che_coleccion')
            ->where('h.che_pais', $country->pai_id)
            ->where('h.che_estado', 'ACTIVO')
            ->where('c.cup_estado', 'ACTIVO')
            ->whereIn('h.che_aplica', ['TODO', 'WEB'])
            ->where(fn ($query) => $query->whereNull('h.che_inicio')->orWhere('h.che_inicio', '<=', $now))
            ->where(fn ($query) => $query->whereNull('h.che_final')->orWhere('h.che_final', '>=', $now))
            ->where(function ($query) use ($email) {
                $query->where('h.che_generico', 'SI');
                if ($email !== '') {
                    $query->orWhere(function ($personal) use ($email) {
                        $personal->where('h.che_generico', 'NO')->whereRaw('LOWER(TRIM(c.cup_correo)) = ?', [$email]);
                    });
                }
            })
            ->orderByDesc('h.che_generico')
            ->orderBy('h.che_final')
            ->get([
                'c.cup_id', 'c.cup_codigo', 'c.cup_monto', 'c.cup_descuento', 'h.che_id', 'h.che_nombre',
                'h.che_nombre_comercial', 'h.che_tipo', 'h.che_generico', 'h.che_checkout', 'h.che_final',
                'h.che_monto', 'h.che_descuento', 'h.che_aplica_promo', 'h.che_solo_primera_compra',
                'h.che_tipo_productos', 'h.che_coleccion', 'category.cat_nombre as genero_nombre',
                'collection.col_nombre as coleccion_nombre',
            ]);

        return $rows->map(function ($row) use ($country) {
            $scope = CouponProductScope::details($row, (string) $country->pai_codigo, (string) config('services.fcm.web_home_url', 'https://stjacks.com'));

            return [
            'id' => (int) $row->cup_id,
            'headerId' => (int) $row->che_id,
            'code' => (string) $row->cup_codigo,
            'source' => $row->che_generico === 'SI' ? 'generic' : 'personal',
            'name' => $row->che_nombre,
            'commercialName' => $row->che_nombre_comercial,
            'type' => $row->che_tipo,
            'discount' => (float) ($row->cup_descuento ?? $row->che_descuento ?? 0),
            'amount' => (float) ($row->cup_monto ?? $row->che_monto ?? 0),
            'checkout' => $row->che_checkout,
            'promotionRule' => $row->che_aplica_promo,
            'firstPurchaseOnly' => $row->che_solo_primera_compra === 'SI',
            'endsAt' => $row->che_final,
            'productScope' => $scope['scope'], 'productScopeLabel' => $scope['label'],
            'productsLink' => $scope['url'], 'productsLinkLabel' => $scope['url'] ? 'Ver productos que aplican' : null,
            ];
        })->unique('id')->values()->all();
    }

    /** @return array<string, mixed> */
    public function revalidate(StorefrontCart $cart, string $email = '', float $shipping = 0): array
    {
        $applications = StorefrontCartCoupon::query()
            ->where('ccu_carrito_id', $cart->getKey())
            ->whereIn('ccu_estado', ['AGREGADO', 'APLICADO', 'NO_APLICABLE'])
            ->orderBy('ccu_id')
            ->lockForUpdate()
            ->get();

        if ($applications->isEmpty()) {
            return ['coupons' => [], 'totals' => ['couponDiscount' => '0.00', 'shippingDiscount' => '0.00']];
        }

        $items = $cart->items()->where('cad_seleccionado', true)->where('cad_estado', 'DISPONIBLE')->get();
        if ($items->isEmpty()) {
            foreach ($applications as $application) {
                $this->updateApplication($application, null, $cart, $email, 'NO_APLICABLE', 'CARRITO_VACIO', 'El carrito no contiene productos disponibles.');
            }

            return ['coupons' => $applications->map(fn ($row) => $this->applicationPayload($row->fresh()))->all(), 'totals' => ['couponDiscount' => '0.00', 'shippingDiscount' => '0.00']];
        }

        $promotion = $this->promotions->resolve([
            'countryId' => (int) $cart->car_pais_id,
            'checkoutType' => (string) $cart->car_tipo,
            'storeId' => $cart->car_tipo === 'TIENDA' ? (int) $cart->car_tienda_id : null,
            'storeCode' => (string) $cart->car_tienda_codigo_snapshot,
            'lines' => $items->map(fn ($item) => [
                'key' => (string) $item->getKey(), 'productId' => (int) $item->cad_producto_id,
                'quantity' => (int) $item->cad_cantidad, 'unitPrice' => (float) $item->cad_precio_unitario,
            ])->all(),
        ]);
        $promoted = collect($promotion['lines'])->keyBy('key');
        $resolved = $this->coupons->resolve([
            'countryId' => (int) $cart->car_pais_id,
            'checkoutType' => (string) $cart->car_tipo,
            'email' => $this->email($email !== '' ? $email : (string) $applications->first()->ccu_correo_snapshot),
            'couponIds' => $applications->pluck('ccu_cupon_id')->all(),
            'shipping' => $shipping,
            'hasApprovedOrder' => $this->hasApprovedOrder($email !== '' ? $email : (string) $applications->first()->ccu_correo_snapshot),
            'lines' => $items->map(function ($item) use ($promoted) {
                $line = $promoted->get((string) $item->getKey());

                return [
                    'key' => (string) $item->getKey(), 'productId' => (int) $item->cad_producto_id,
                    'quantity' => (int) $item->cad_cantidad, 'unitPrice' => (float) $item->cad_precio_unitario,
                    'promotionDiscount' => (float) ($line['discount'] ?? 0), 'promotion' => $line['promotion'] ?? null,
                ];
            })->all(),
        ]);

        $results = collect($resolved['coupons'])->keyBy('id');
        foreach ($applications as $application) {
            $result = $results->get((int) $application->ccu_cupon_id);
            $status = $result ? (string) $result['status'] : 'NO_APLICABLE';
            $reasonCode = $result ? ($result['reasonCode'] ?? null) : 'CUPON_NO_ENCONTRADO';
            $reason = $result ? ($result['reason'] ?? null) : 'El cupón no pudo validarse.';

            $this->updateApplication(
                $application, $result, $cart, $email,
                $status, $reasonCode, $reason,
            );
        }

        return [
            ...$resolved,
            'applications' => $applications
                ->map(fn ($row) => $this->applicationPayload($row->fresh()))
                ->reject(fn (array $application) => $application['reasonCode'] === 'CUPON_INACTIVO')
                ->values()
                ->all(),
        ];
    }

    private function cart(string $countryCode, StorefrontVisitor $visitor, ?StorefrontCustomer $customer): StorefrontCart
    {
        $country = DB::table('stj_paises')->whereRaw('LOWER(pai_codigo) = ?', [strtolower($countryCode)])->first(['pai_id']);
        if (! $country) {
            throw ValidationException::withMessages(['country' => 'El país no existe.']);
        }

        $query = StorefrontCart::query()->where('car_pais_id', $country->pai_id)->whereIn('car_estado', ['ACTIVO', 'CHECKOUT']);
        $customer ? $query->where('car_usu_id', $customer->getKey()) : $query->whereNull('car_usu_id')->where('car_visitante_id', $visitor->getKey());
        $cart = $query->lockForUpdate()->first();
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => 'No existe un carrito activo.']);
        }

        return $cart;
    }

    private function updateApplication(StorefrontCartCoupon $application, ?array $result, StorefrontCart $cart, string $email, string $status, ?string $reasonCode, ?string $reason): void
    {
        $application->forceFill([
            'ccu_estado' => match ($status) {
                'APLICADO' => 'APLICADO',
                'PENDIENTE_CORREO' => 'AGREGADO',
                default => 'NO_APLICABLE',
            },
            'ccu_razon_codigo' => $reasonCode,
            'ccu_razon_mensaje' => $reason,
            'ccu_carrito_version' => $cart->car_version,
            'ccu_correo_snapshot' => $this->email($email !== '' ? $email : (string) $application->ccu_correo_snapshot),
            'ccu_pais_id' => $cart->car_pais_id,
            'ccu_checkout_snapshot' => $cart->car_tipo,
            'ccu_descuento_productos' => ($result['type'] ?? null) === 'ENVIO_GRATIS' ? 0 : (float) ($result['discount'] ?? 0),
            'ccu_descuento_envio' => ($result['type'] ?? null) === 'ENVIO_GRATIS' ? (float) ($result['discount'] ?? 0) : 0,
            'ccu_validado_en' => now(),
            'ccu_actualizado_en' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function applicationPayload(StorefrontCartCoupon $row): array
    {
        $coupon = DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->join('stj_paises as country', 'country.pai_id', '=', 'h.che_pais')
            ->leftJoin('stj_categorias as category', 'category.cat_id', '=', 'h.che_genero')
            ->leftJoin('stj_coleccion as collection', 'collection.col_id', '=', 'h.che_coleccion')
            ->where('c.cup_id', $row->ccu_cupon_id)
            ->first(['h.che_id', 'h.che_nombre', 'h.che_nombre_comercial', 'h.che_tipo_productos', 'h.che_aplica_promo', 'h.che_coleccion', 'category.cat_nombre as genero_nombre', 'collection.col_nombre as coleccion_nombre', 'country.pai_codigo']);
        $scope = $coupon
            ? CouponProductScope::details($coupon, (string) $coupon->pai_codigo, (string) config('services.fcm.web_home_url', 'https://stjacks.com'))
            : ['scope' => 'NA', 'label' => null, 'url' => null];

        $displayStatus = $row->ccu_estado === 'AGREGADO' && $row->ccu_razon_codigo === 'CORREO_PENDIENTE'
            ? 'PENDIENTE_CORREO'
            : $row->ccu_estado;

        return [
            'id' => (int) $row->getKey(), 'couponId' => (int) $row->ccu_cupon_id, 'code' => $row->ccu_codigo,
            'status' => $displayStatus, 'reasonCode' => $row->ccu_razon_codigo, 'reason' => $row->ccu_razon_mensaje,
            'productDiscount' => number_format((float) $row->ccu_descuento_productos, 2, '.', ''),
            'shippingDiscount' => number_format((float) $row->ccu_descuento_envio, 2, '.', ''),
            'productScope' => $scope['scope'], 'productScopeLabel' => $scope['label'],
            'productsLink' => $scope['url'], 'productsLinkLabel' => $scope['url'] ? 'Ver productos que aplican' : null,
        ];
    }

    private function email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function hasApprovedOrder(string $email): bool
    {
        $email = $this->email($email);
        if ($email === '' || ! Schema::hasTable('stj_pedidos') || ! Schema::hasTable('stj_pedidos_pago')) {
            return false;
        }

        return DB::table('stj_pedidos as p')
            ->join('stj_pedidos_pago as pay', 'pay.ppa_pedido', '=', 'p.ped_id')
            ->whereRaw('LOWER(TRIM(p.ped_email)) = ?', [$email])
            ->where('pay.ppa_estado', 'APROBADA')
            ->exists();
    }
}
