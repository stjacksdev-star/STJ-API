<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StorefrontPromotionResolver
{
    public function __construct(
        private readonly PromotionLabelGenerator $labels,
    ) {}

    /**
     * Resolves promotions for a complete commercial basket. Passing one line
     * provides the product-card/PDP preview contract without special logic.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function resolve(array $context): array
    {
        $normalized = $this->normalizeContext($context);
        $promotions = $this->eligiblePromotions($normalized);
        $evaluations = $promotions
            ->map(fn (array $promotion) => $this->evaluate($promotion, $normalized))
            ->filter(fn (array $evaluation) => $evaluation['totalBenefitCents'] > 0
                || ($normalized['includeUntriggered'] && $evaluation['eligibleLineKeys'] !== []))
            ->values();

        $resolvedLines = collect($normalized['lines'])
            ->map(function (array $line) use ($evaluations, $normalized) {
                $candidates = $evaluations
                    ->filter(fn (array $evaluation) => ($evaluation['allocations'][$line['key']] ?? 0) > 0
                        || ($normalized['includeUntriggered']
                            && in_array($line['key'], $evaluation['eligibleLineKeys'], true)))
                    ->map(fn (array $evaluation) => [
                        ...$evaluation,
                        'lineBenefitCents' => $evaluation['allocations'][$line['key']],
                    ])
                    ->values();
                $selected = $this->select($candidates);

                if ($candidates->count() > 1 && $selected !== null) {
                    $this->reportConflict($normalized, $line, $candidates, $selected);
                }

                $discountCents = (int) ($selected['lineBenefitCents'] ?? 0);
                $lineBaseCents = $line['unitPriceCents'] * $line['quantity'];
                $promotion = $selected === null
                    ? null
                    : $this->promotionPayload($selected, $normalized, $line, $discountCents);

                return [
                    'key' => $line['key'],
                    'productId' => $line['productId'],
                    'quantity' => $line['quantity'],
                    'baseUnitPrice' => $this->decimal($line['unitPriceCents']),
                    'baseTotal' => $this->decimal($lineBaseCents),
                    'discount' => $this->decimal($discountCents),
                    'finalTotal' => $this->decimal(max(0, $lineBaseCents - $discountCents)),
                    'promotion' => $promotion,
                ];
            })
            ->values();

        $baseCents = $resolvedLines->sum(fn (array $line) => $this->cents($line['baseTotal']));
        $discountCents = $resolvedLines->sum(fn (array $line) => $this->cents($line['discount']));

        return [
            'context' => [
                'countryId' => $normalized['countryId'],
                'checkoutType' => $normalized['checkoutType'],
                'storeId' => $normalized['storeId'],
                'referenceTime' => $normalized['at']->format('Y-m-d H:i:s'),
            ],
            'lines' => $resolvedLines->all(),
            'totals' => [
                'base' => $this->decimal($baseCents),
                'discount' => $this->decimal($discountCents),
                'final' => $this->decimal(max(0, $baseCents - $discountCents)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $countryId = (int) ($context['countryId'] ?? 0);
        $checkoutType = strtoupper(trim((string) ($context['checkoutType'] ?? '')));
        $storeId = isset($context['storeId']) ? (int) $context['storeId'] : null;
        $storeName = trim((string) ($context['storeName'] ?? ''));

        if ($countryId < 1) {
            throw ValidationException::withMessages(['countryId' => 'El país es obligatorio.']);
        }
        if (! in_array($checkoutType, ['DOMICILIO', 'TIENDA'], true)) {
            throw ValidationException::withMessages(['checkoutType' => 'La modalidad debe ser DOMICILIO o TIENDA.']);
        }
        if ($checkoutType === 'TIENDA' && (! $storeId || $storeId < 1)) {
            $storeCode = trim((string) ($context['storeCode'] ?? ''));
            $store = $storeCode === '' ? null : DB::table('stj_tiendas')
                ->where('tie_pais', $countryId)
                ->where('tie_codigo', $storeCode)
                ->first(['tie_id', 'tie_nombre']);
            $storeId = $store ? (int) $store->tie_id : null;
            $storeName = $storeName !== '' ? $storeName : trim((string) ($store->tie_nombre ?? ''));
        }
        if ($checkoutType === 'TIENDA' && (! $storeId || $storeId < 1)) {
            throw ValidationException::withMessages(['storeId' => 'La tienda es obligatoria para retiro en tienda.']);
        }

        $lines = collect($context['lines'] ?? [])->map(function (array $line, int $index) {
            $productId = (int) ($line['productId'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitPriceCents = $this->cents($line['unitPrice'] ?? 0);

            if ($productId < 1 || $quantity < 1 || $unitPriceCents < 1) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'Cada línea requiere producto, cantidad y precio base válidos.',
                ]);
            }

            return [
                'key' => (string) ($line['key'] ?? $index),
                'productId' => $productId,
                'quantity' => $quantity,
                'unitPriceCents' => $unitPriceCents,
            ];
        })->values()->all();

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Debe proporcionar al menos una línea.']);
        }
        if (collect($lines)->pluck('key')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Las llaves de las líneas no pueden repetirse.']);
        }

        $timezone = (string) config('promotions.timezone', 'America/El_Salvador');
        $at = isset($context['at'])
            ? Carbon::parse($context['at'], $timezone)->setTimezone($timezone)
            : Carbon::now($timezone);

        return [
            'countryId' => $countryId,
            'checkoutType' => $checkoutType,
            'storeId' => $checkoutType === 'TIENDA' ? $storeId : null,
            'storeName' => $storeName,
            'currencySymbol' => (string) ($context['currencySymbol'] ?? '$'),
            'at' => $at,
            'lines' => $lines,
            'promotionId' => isset($context['promotionId']) ? (int) $context['promotionId'] : null,
            'includeUntriggered' => (bool) ($context['includeUntriggered'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Collection<int, array<string, mixed>>
     */
    private function eligiblePromotions(array $context): Collection
    {
        $at = $context['at']->format('Y-m-d H:i:s');
        $productIds = collect($context['lines'])->pluck('productId')->unique()->values();
        $promotions = DB::table('stj_promociones as p')
            ->join('stj_promociones_horario as h', function ($join) {
                $join->on('h.pho_promocion', '=', 'p.prm_id')
                    ->where('h.pho_tipo', '=', 'NORMAL');
            })
            ->where('p.prm_pais', $context['countryId'])
            ->where('p.prm_estado', 'EN-PROCESO')
            ->where('p.prm_modalidad', 'PROGRAMADO')
            ->whereIn('p.prm_origen', ['WEB', 'TODO'])
            ->where('h.pho_inicio', '<=', $at)
            ->where('h.pho_fin', '>', $at)
            ->whereIn('h.pho_estado', ['ACTIVO', 'PENDIENTE'])
            ->when($context['promotionId'], fn ($query, $promotionId) => $query->where('p.prm_id', $promotionId))
            ->get([
                'p.prm_id',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
                'p.prm_tipo',
                'p.prm_tipo_promocion',
                'p.prm_restriccion',
                'p.prm_porcentaje',
                'p.prm_precio',
                'p.prm_tipo_checkout',
                'p.prm_alcance_tienda',
                'p.prm_aplica',
                'h.pho_inicio',
                'h.pho_fin',
            ])
            ->unique('prm_id')
            ->values();

        $ids = $promotions->pluck('prm_id');
        $products = DB::table('stj_promociones_producto')
            ->whereIn('ppr_promocion', $ids)
            ->whereIn('ppr_producto', $productIds)
            ->get(['ppr_promocion', 'ppr_producto', 'ppr_descuento', 'ppr_precio'])
            ->groupBy('ppr_promocion');
        $stores = DB::table('stj_promociones_tienda')
            ->whereIn('prt_promocion', $ids)
            ->get(['prt_promocion', 'prt_tienda'])
            ->groupBy('prt_promocion');

        return $promotions
            ->map(function (object $promotion) use ($products, $stores, $context) {
                $promotionProducts = $products->get($promotion->prm_id, collect());
                $data = [
                    'id' => (int) $promotion->prm_id,
                    'name' => trim((string) $promotion->prm_nombre),
                    'commercialName' => trim((string) $promotion->prm_nombre_comercial),
                    'type' => (string) $promotion->prm_tipo,
                    'promotionType' => (string) $promotion->prm_tipo_promocion,
                    'restriction' => $promotion->prm_restriccion,
                    'percentage' => $promotion->prm_porcentaje !== null ? (float) $promotion->prm_porcentaje : null,
                    'price' => $promotion->prm_precio !== null ? (float) $promotion->prm_precio : null,
                    'checkoutType' => (string) ($promotion->prm_tipo_checkout ?? 'TODO'),
                    'storeScope' => $promotion->prm_alcance_tienda,
                    'appliesTo' => (string) ($promotion->prm_aplica ?? 'TODO'),
                    'startsAt' => $promotion->pho_inicio,
                    'endsAt' => $promotion->pho_fin,
                    'products' => $promotionProducts->keyBy('ppr_producto'),
                    'selectedStoreIds' => $stores->get($promotion->prm_id, collect())
                        ->pluck('prt_tienda')
                        ->map(fn ($id) => (int) $id)
                        ->all(),
                ];
                $data['priority'] = $this->scopePriority($data, $context);

                return $data;
            })
            ->filter(fn (array $promotion) => $promotion['priority'] > 0)
            ->filter(fn (array $promotion) => $promotion['type'] === 'TODO' || $promotion['products']->isNotEmpty())
            ->values();
    }

    /**
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $context
     */
    private function scopePriority(array $promotion, array $context): int
    {
        $checkout = strtoupper($promotion['checkoutType']);
        $scope = strtoupper((string) $promotion['storeScope']);

        if ($checkout === 'D') {
            return $context['checkoutType'] === 'DOMICILIO' ? 2 : 0;
        }

        if ($checkout === 'T') {
            if ($context['checkoutType'] !== 'TIENDA') {
                return 0;
            }
            if ($scope === 'SELECCIONADAS') {
                return in_array($context['storeId'], $promotion['selectedStoreIds'], true) ? 4 : 0;
            }

            return $scope === 'TODAS' || $scope === '' ? 3 : 0;
        }

        // TODO + TODAS and TODO without detailed scope retain legacy behavior.
        if ($checkout === 'TODO' || $checkout === '') {
            if ($scope === 'SELECCIONADAS') {
                if ($context['checkoutType'] !== 'TIENDA') {
                    return 0;
                }

                return in_array($context['storeId'], $promotion['selectedStoreIds'], true) ? 4 : 0;
            }
            if ($promotion['appliesTo'] === 'ENTREGA-DOMICILIO' && $context['checkoutType'] !== 'DOMICILIO') {
                return 0;
            }
            if ($promotion['appliesTo'] === 'ENTREGA-TIENDA' && $context['checkoutType'] !== 'TIENDA') {
                return 0;
            }

            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function evaluate(array $promotion, array $context): array
    {
        $eligibleLines = collect($context['lines'])->filter(
            fn (array $line) => $promotion['type'] === 'TODO' || $promotion['products']->has($line['productId'])
        );
        $allocations = [];

        foreach ($eligibleLines as $line) {
            $allocations[$line['key']] = 0;
        }

        if (in_array($promotion['promotionType'], ['DESCUENTO', 'DESCUENTO-SKU'], true)) {
            foreach ($eligibleLines as $line) {
                $productRule = $promotion['products']->get($line['productId']);
                $percentage = (float) ($promotion['percentage'] ?: ($productRule->ppr_descuento ?? 0));
                $percentage = max(0, min(100, $percentage));
                $allocations[$line['key']] = (int) round(
                    $line['unitPriceCents'] * $line['quantity'] * $percentage / 100,
                    0,
                    PHP_ROUND_HALF_UP,
                );
            }
        } elseif ($promotion['promotionType'] === 'PUNTO-PRECIO') {
            foreach ($eligibleLines as $line) {
                $productRule = $promotion['products']->get($line['productId']);
                $price = $promotion['price'] ?: ($productRule->ppr_precio ?? null);
                if ($price === null) {
                    continue;
                }
                $promotionalCents = $this->cents($price);
                $allocations[$line['key']] = max(0, $line['unitPriceCents'] - $promotionalCents) * $line['quantity'];
            }
        } elseif ($promotion['promotionType'] === 'CONDICION-SKU') {
            $allocations = $this->conditionalAllocations($promotion, $eligibleLines, $allocations);
        }

        return [
            ...$promotion,
            'allocations' => $allocations,
            'eligibleLineKeys' => $eligibleLines->pluck('key')->all(),
            'totalBenefitCents' => array_sum($allocations),
        ];
    }

    /**
     * @param  array<string, mixed>  $promotion
     * @param  Collection<int, array<string, mixed>>  $lines
     * @param  array<string, int>  $allocations
     * @return array<string, int>
     */
    private function conditionalAllocations(array $promotion, Collection $lines, array $allocations): array
    {
        $units = $lines
            ->flatMap(fn (array $line) => array_fill(0, $line['quantity'], [
                'key' => $line['key'],
                'price' => $line['unitPriceCents'],
            ]))
            ->sortBy('price')
            ->values();
        $pairs = intdiv($units->count(), 2);

        if ($pairs < 1) {
            return $allocations;
        }

        $restriction = (string) $promotion['restriction'];
        $discountedUnits = match ($restriction) {
            '2xPP' => $pairs * 2,
            '2x1', '21/2', '2doPrecio' => $pairs,
            default => 0,
        };
        $targetCents = $promotion['price'] !== null ? $this->cents($promotion['price']) : 0;

        foreach ($units->take($discountedUnits) as $unit) {
            $benefit = match ($restriction) {
                '2x1' => $unit['price'],
                '21/2' => (int) round($unit['price'] / 2, 0, PHP_ROUND_HALF_UP),
                '2doPrecio' => max(0, $unit['price'] - $targetCents),
                '2xPP' => max(0, $unit['price'] - (int) round($targetCents / 2, 0, PHP_ROUND_HALF_UP)),
                default => 0,
            };
            $allocations[$unit['key']] += $benefit;
        }

        return $allocations;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function select(Collection $candidates): ?array
    {
        return $candidates
            ->sort(function (array $left, array $right) {
                return [$right['priority'], $right['lineBenefitCents'], $right['id']]
                    <=> [$left['priority'], $left['lineBenefitCents'], $left['id']];
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $selected
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function promotionPayload(array $selected, array $context, array $line, int $discountCents): array
    {
        $productRule = $selected['products']->get($line['productId']);
        $percentage = $selected['percentage'] ?: ($productRule->ppr_descuento ?? null);
        $labelData = [
            ...$selected,
            'percentage' => $percentage,
            'price' => $selected['price'] ?: ($productRule->ppr_precio ?? null),
        ];

        return [
            'id' => $selected['id'],
            'type' => $selected['promotionType'],
            'restriction' => $selected['restriction'],
            'name' => $selected['name'],
            'commercialName' => $selected['commercialName'],
            ...$this->labels->generate($labelData, $context),
            'discount' => $this->decimal($discountCents),
            'discountPercentage' => $percentage !== null ? (float) $percentage : null,
            'modality' => $context['checkoutType'],
            'checkoutType' => $selected['checkoutType'],
            'storeScope' => $selected['storeScope'],
            'availabilityLabel' => $this->customerScopeLabel($selected),
            'participatingStoreCount' => count($selected['selectedStoreIds']),
            'store' => $context['checkoutType'] === 'TIENDA' ? [
                'id' => $context['storeId'],
                'name' => $context['storeName'] ?: null,
            ] : null,
            'startsAt' => $selected['startsAt'],
            'endsAt' => $selected['endsAt'],
        ];
    }

    private function customerScopeLabel(array $promotion): string
    {
        $checkout = strtoupper((string) $promotion['checkoutType']);
        $scope = strtoupper((string) $promotion['storeScope']);

        if ($checkout === 'D') {
            return 'Promoción válida para compras a domicilio';
        }
        if (in_array($checkout, ['', 'TODO', 'T'], true) && $scope === 'SELECCIONADAS') {
            $count = count($promotion['selectedStoreIds']);

            return 'Promoción válida en tiendas seleccionadas'.($count > 0 ? " · Válida en {$count} ".($count === 1 ? 'tienda' : 'tiendas') : '');
        }
        if ($checkout === 'T') {
            return 'Promoción válida en todas nuestras tiendas';
        }

        return 'Promoción válida para compras a domicilio y en todas nuestras tiendas';
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $line
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $selected
     */
    private function reportConflict(array $context, array $line, Collection $candidates, array $selected): void
    {
        $ids = $candidates->pluck('id')->sort()->values()->all();
        $key = 'promotion-conflict:'.hash('sha256', implode('|', [
            $context['countryId'],
            $line['productId'],
            $context['checkoutType'],
            $context['storeId'] ?? 0,
            implode(',', $ids),
        ]));
        $ttl = max(1, (int) config('promotions.conflict_deduplication_seconds', 1800));

        if (! Cache::add($key, true, $ttl)) {
            return;
        }

        Log::warning('PROMOTION_CONFLICT', [
            'event' => 'PROMOTION_CONFLICT',
            'country_id' => $context['countryId'],
            'product_id' => $line['productId'],
            'checkout_type' => $context['checkoutType'],
            'store_id' => $context['storeId'],
            'candidate_promotion_ids' => $ids,
            'selected_promotion_id' => $selected['id'],
            'selection_reason' => $this->selectionReason($candidates, $selected),
            'occurred_at' => $context['at']->toIso8601String(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $selected
     */
    private function selectionReason(Collection $candidates, array $selected): string
    {
        if ($candidates->where('priority', $selected['priority'])->count() !== $candidates->count()) {
            return 'SCOPE_PRIORITY';
        }
        if ($candidates->where('lineBenefitCents', $selected['lineBenefitCents'])->count() !== $candidates->count()) {
            return 'GREATER_ECONOMIC_BENEFIT';
        }

        return 'GREATER_PROMOTION_ID';
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
