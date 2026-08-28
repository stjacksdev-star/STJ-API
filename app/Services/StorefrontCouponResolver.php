<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontCouponResolver
{
    /**
     * Resolve coupons after promotions have already been calculated.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolve(array $context): array
    {
        $data = $this->normalize($context);
        $coupons = $this->coupons($data);
        $lines = collect($data['lines'])->keyBy('key');
        $results = [];

        foreach ($coupons as $coupon) {
            $validation = $this->validateCoupon($coupon, $data, $lines);
            if (! $validation['valid']) {
                $status = $validation['code'] === 'CORREO_PENDIENTE' ? 'PENDIENTE_CORREO' : 'NO_APLICABLE';
                $results[] = $this->couponResult($coupon, $status, $validation['code'], $validation['message']);

                continue;
            }

            $productRules = $coupon['products']->keyBy('cpr_producto');
            $benefit = 0;

            if ($coupon['type'] === 'ENVIO_GRATIS') {
                $benefit = $data['checkoutType'] === 'DOMICILIO' ? $data['shippingCents'] : 0;
                if ($benefit > 0) {
                    $data['shippingCents'] = 0;
                }
            } else {
                foreach ($lines as $key => $line) {
                    if (! $this->lineIsEligible($coupon, $line, $productRules)) {
                        continue;
                    }

                    $lineBenefit = $this->lineBenefit($coupon, $line, $productRules->get($line['productId']));
                    if ($lineBenefit < 1) {
                        continue;
                    }

                    $line['couponDiscountCents'] += $lineBenefit;
                    $line['currentTotalCents'] -= $lineBenefit;
                    $line['coupons'][] = [
                        'id' => $coupon['id'],
                        'headerId' => $coupon['headerId'],
                        'code' => $coupon['code'],
                        'type' => $coupon['type'],
                        'discount' => $this->decimal($lineBenefit),
                    ];
                    $lines[$key] = $line;
                    $benefit += $lineBenefit;
                }
            }

            $results[] = $benefit > 0
                ? $this->couponResult($coupon, 'APLICADO', null, null, $benefit)
                : $this->couponResult($coupon, 'NO_APLICABLE', 'SIN_BENEFICIO', 'El cupón no produce un beneficio en el carrito actual.');
        }

        $resolvedLines = $lines->map(function (array $line) {
            $effectiveDiscount = $line['baseTotalCents'] > 0
                ? (($line['baseTotalCents'] - $line['currentTotalCents']) * 100) / $line['baseTotalCents']
                : 0;

            return [
                'key' => $line['key'],
                'productId' => $line['productId'],
                'quantity' => $line['quantity'],
                'regularUnitPrice' => $this->decimal($line['unitPriceCents']),
                'baseTotal' => $this->decimal($line['baseTotalCents']),
                'promotionDiscount' => $this->decimal($line['promotionDiscountCents']),
                'couponDiscount' => $this->decimal($line['couponDiscountCents']),
                'effectiveDiscountPercentage' => round($effectiveDiscount, 6),
                'finalTotal' => $this->decimal($line['currentTotalCents']),
                'coupons' => $line['coupons'],
            ];
        })->values();

        $base = $resolvedLines->sum(fn (array $line) => $this->cents($line['baseTotal']));
        $promotionDiscount = $resolvedLines->sum(fn (array $line) => $this->cents($line['promotionDiscount']));
        $couponDiscount = $resolvedLines->sum(fn (array $line) => $this->cents($line['couponDiscount']));
        $shippingDiscount = max(0, $data['originalShippingCents'] - $data['shippingCents']);

        return [
            'lines' => $resolvedLines->all(),
            'coupons' => $results,
            'totals' => [
                'base' => $this->decimal($base),
                'promotionDiscount' => $this->decimal($promotionDiscount),
                'couponDiscount' => $this->decimal($couponDiscount),
                'shipping' => $this->decimal($data['shippingCents']),
                'shippingDiscount' => $this->decimal($shippingDiscount),
                'final' => $this->decimal(max(0, $base - $promotionDiscount - $couponDiscount + $data['shippingCents'])),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function normalize(array $context): array
    {
        $countryId = (int) ($context['countryId'] ?? 0);
        $checkoutType = strtoupper(trim((string) ($context['checkoutType'] ?? '')));
        $email = mb_strtolower(trim((string) ($context['email'] ?? '')));
        $couponIds = collect($context['couponIds'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        if ($countryId < 1) {
            throw ValidationException::withMessages(['countryId' => 'El país es obligatorio.']);
        }
        if (! in_array($checkoutType, ['DOMICILIO', 'TIENDA'], true)) {
            throw ValidationException::withMessages(['checkoutType' => 'La modalidad debe ser DOMICILIO o TIENDA.']);
        }
        if ($couponIds === []) {
            throw ValidationException::withMessages(['couponIds' => 'Debe proporcionar al menos un cupón.']);
        }

        $lines = collect($context['lines'] ?? [])->map(function (array $line, int $index) {
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitPrice = $this->cents($line['unitPrice'] ?? 0);
            $promotionDiscount = $this->cents($line['promotionDiscount'] ?? 0);
            $base = $unitPrice * $quantity;

            if ((int) ($line['productId'] ?? 0) < 1 || $quantity < 1 || $unitPrice < 1) {
                throw ValidationException::withMessages(["lines.{$index}" => 'Cada línea requiere producto, cantidad y precio regular válidos.']);
            }
            if ($promotionDiscount < 0 || $promotionDiscount >= $base) {
                throw ValidationException::withMessages(["lines.{$index}.promotionDiscount" => 'El descuento promocional debe dejar un total positivo.']);
            }

            return [
                'key' => (string) ($line['key'] ?? $index),
                'productId' => (int) $line['productId'],
                'quantity' => $quantity,
                'unitPriceCents' => $unitPrice,
                'baseTotalCents' => $base,
                'promotionDiscountCents' => $promotionDiscount,
                'couponDiscountCents' => 0,
                'currentTotalCents' => $base - $promotionDiscount,
                'couponPercentageBaseCents' => $base - $promotionDiscount,
                'hasPromotion' => $promotionDiscount > 0 || ! empty($line['promotion']),
                'coupons' => [],
            ];
        })->values()->all();

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Debe proporcionar al menos una línea.']);
        }

        $shipping = $this->cents($context['shipping'] ?? 0);

        return [
            'countryId' => $countryId,
            'checkoutType' => $checkoutType,
            'email' => $email,
            'couponIds' => $couponIds,
            'at' => Carbon::parse($context['at'] ?? now(), config('app.timezone')),
            'hasApprovedOrder' => (bool) ($context['hasApprovedOrder'] ?? false),
            'usedNonMultipleCouponIds' => collect($context['usedNonMultipleCouponIds'] ?? [])->map(fn ($id) => (int) $id)->all(),
            'lines' => $lines,
            'subtotalCents' => collect($lines)->sum('baseTotalCents'),
            'shippingCents' => $shipping,
            'originalShippingCents' => $shipping,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function coupons(array $context): Collection
    {
        $rows = DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->whereIn('c.cup_id', $context['couponIds'])
            ->get([
                'c.cup_id', 'c.cup_header', 'c.cup_codigo', 'c.cup_estado', 'c.cup_monto', 'c.cup_descuento', 'c.cup_correo',
                'h.che_nombre', 'h.che_tipo', 'h.che_aplica', 'h.che_checkout', 'h.che_generico', 'h.che_pais',
                'h.che_inicio', 'h.che_final', 'h.che_monto', 'h.che_descuento', 'h.che_aplica_monto_minimo',
                'h.che_monto_minimo', 'h.che_multiple', 'h.che_aplica_promo', 'h.che_solo_primera_compra',
                'h.che_estado', 'h.che_tipo_productos',
            ]);
        $products = DB::table('stj_cupones_producto')
            ->whereIn('cpr_cupon', $rows->pluck('cup_header'))
            ->get(['cpr_cupon', 'cpr_producto', 'cpr_descuento', 'cpr_precio'])
            ->groupBy('cpr_cupon');

        return collect($context['couponIds'])->map(function (int $id) use ($rows, $products) {
            $row = $rows->firstWhere('cup_id', $id);
            if (! $row) {
                return ['id' => $id, 'missing' => true, 'headerId' => 0, 'code' => '', 'type' => '', 'products' => collect()];
            }

            return [
                'id' => (int) $row->cup_id,
                'headerId' => (int) $row->cup_header,
                'code' => (string) $row->cup_codigo,
                'name' => (string) $row->che_nombre,
                'type' => (string) $row->che_tipo,
                'detailState' => (string) $row->cup_estado,
                'headerState' => (string) $row->che_estado,
                'channel' => (string) $row->che_aplica,
                'checkout' => (string) $row->che_checkout,
                'generic' => (string) $row->che_generico,
                'countryId' => (int) $row->che_pais,
                'startsAt' => $row->che_inicio,
                'endsAt' => $row->che_final,
                'detailAmount' => $row->cup_monto,
                'detailDiscount' => $row->cup_descuento,
                'headerAmount' => $row->che_monto,
                'headerDiscount' => $row->che_descuento,
                'email' => mb_strtolower(trim((string) $row->cup_correo)),
                'minimumEnabled' => (string) $row->che_aplica_monto_minimo,
                'minimumAmount' => $row->che_monto_minimo,
                'multiple' => (string) ($row->che_multiple ?? 'NO'),
                'promotionRule' => (string) ($row->che_aplica_promo ?? 'TODOS'),
                'firstPurchaseOnly' => (string) ($row->che_solo_primera_compra ?? 'NO'),
                'productType' => (string) ($row->che_tipo_productos ?? 'NA'),
                'products' => $products->get($row->cup_header, collect()),
                'missing' => false,
            ];
        });
    }

    /** @return array{valid: bool, code: ?string, message: ?string} */
    private function validateCoupon(array $coupon, array $context, Collection $lines): array
    {
        $invalid = fn (string $code, string $message) => ['valid' => false, 'code' => $code, 'message' => $message];

        if ($coupon['missing']) {
            return $invalid('CUPON_NO_ENCONTRADO', 'El cupón no existe.');
        }
        if ($coupon['headerState'] !== 'ACTIVO' || $coupon['detailState'] !== 'ACTIVO') {
            return $invalid('CUPON_INACTIVO', 'El cupón no está activo.');
        }
        if (! in_array($coupon['channel'], ['TODO', 'WEB'], true)) {
            return $invalid('CANAL_NO_PERMITIDO', 'El cupón no aplica en Web.');
        }
        if ($coupon['countryId'] !== $context['countryId']) {
            return $invalid('PAIS_NO_PERMITIDO', 'El cupón no aplica para el país actual.');
        }
        if (! in_array($coupon['checkout'], ['TODO', $context['checkoutType']], true)) {
            return $invalid('CHECKOUT_NO_PERMITIDO', 'El cupón no aplica para la modalidad actual.');
        }
        if ($coupon['startsAt'] && $context['at']->lt(Carbon::parse($coupon['startsAt']))) {
            return $invalid('CUPON_NO_INICIADO', 'El cupón aún no está disponible.');
        }
        if ($coupon['endsAt'] && $context['at']->gt(Carbon::parse($coupon['endsAt']))) {
            return $invalid('CUPON_VENCIDO', 'El cupón ha vencido.');
        }
        if ($coupon['generic'] !== 'SI' && $context['email'] === '') {
            return $invalid('CORREO_PENDIENTE', 'Ingresa en el checkout el correo al que fue enviado este cupón para validarlo.');
        }
        if ($coupon['generic'] !== 'SI' && $coupon['email'] !== $context['email']) {
            return $invalid('CORREO_NO_COINCIDE', 'El cupón no pertenece al correo del checkout.');
        }
        if ($coupon['multiple'] !== 'SI' && in_array($coupon['id'], $context['usedNonMultipleCouponIds'], true)) {
            return $invalid('CUPON_YA_UTILIZADO', 'Este cupón ya fue utilizado por este correo en un pedido aprobado.');
        }
        if ($coupon['firstPurchaseOnly'] === 'SI' && $context['hasApprovedOrder']) {
            return $invalid('PRIMERA_COMPRA_REQUERIDA', 'El cupón es exclusivo para la primera compra.');
        }
        if ($coupon['minimumEnabled'] === 'SI' && $context['subtotalCents'] < $this->cents($coupon['minimumAmount'])) {
            return $invalid('MONTO_MINIMO_NO_ALCANZADO', 'El carrito no alcanza el monto mínimo del cupón.');
        }
        if ($coupon['type'] === 'ENVIO_GRATIS' && $context['checkoutType'] !== 'DOMICILIO') {
            return $invalid('CHECKOUT_NO_PERMITIDO', 'El envío gratis requiere entrega a domicilio.');
        }
        if ($coupon['type'] !== 'ENVIO_GRATIS' && ! $lines->contains(fn (array $line) => $this->lineIsEligible($coupon, $line, $coupon['products']->keyBy('cpr_producto')))) {
            return $invalid('SIN_PRODUCTOS_ELEGIBLES', 'El carrito no contiene productos elegibles para el cupón.');
        }

        return ['valid' => true, 'code' => null, 'message' => null];
    }

    private function lineIsEligible(array $coupon, array $line, Collection $productRules): bool
    {
        if (in_array($coupon['productType'], ['PLA', 'GEN', 'COL'], true) && ! $productRules->has($line['productId'])) {
            return false;
        }
        if ($coupon['promotionRule'] === 'REGULAR' && $line['hasPromotion']) {
            return false;
        }
        if ($coupon['promotionRule'] === 'PROMO' && ! $line['hasPromotion']) {
            return false;
        }

        return true;
    }

    private function lineBenefit(array $coupon, array $line, ?object $productRule): int
    {
        // Every line must retain at least one cent, which guarantees an effective discount below 100%.
        $maximumBenefit = max(0, $line['currentTotalCents'] - 1);
        if ($coupon['type'] === 'DESCUENTO') {
            $percentage = (float) ($productRule?->cpr_descuento ?? $coupon['detailDiscount'] ?? $coupon['headerDiscount'] ?? 0);
            if ($percentage <= 0 || $percentage >= 100) {
                return 0;
            }

            // Percentage coupons accumulate additively over the same post-promotion base.
            // Example: 20% + 10% = 30%, instead of applying the second coupon to the remaining balance (28%).
            return min($maximumBenefit, (int) round($line['couponPercentageBaseCents'] * $percentage / 100, 0, PHP_ROUND_HALF_UP));
        }

        $targetUnit = $this->cents($productRule?->cpr_precio ?? $coupon['detailAmount'] ?? $coupon['headerAmount'] ?? 0);
        if ($targetUnit < 1) {
            return 0;
        }
        $targetTotal = $targetUnit * $line['quantity'];

        return min($maximumBenefit, max(0, $line['currentTotalCents'] - $targetTotal));
    }

    /** @return array<string, mixed> */
    private function couponResult(array $coupon, string $status, ?string $reasonCode, ?string $reason, int $benefit = 0): array
    {
        return [
            'id' => $coupon['id'],
            'headerId' => $coupon['headerId'],
            'code' => $coupon['code'],
            'type' => $coupon['type'],
            'status' => $status,
            'reasonCode' => $reasonCode,
            'reason' => $reason,
            'discount' => $this->decimal($benefit),
        ];
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
