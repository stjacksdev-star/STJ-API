<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class StorefrontProductPromotionPresenter
{
    public function __construct(
        private readonly StorefrontPromotionResolver $resolver,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function resolve(Collection $products, int $countryId, string $countryCode, array $context = []): Collection
    {
        if ($products->isEmpty() || ! Schema::hasTable('stj_promociones') || ! Schema::hasTable('stj_promociones_horario')) {
            return collect();
        }

        $resolution = $this->resolver->resolve([
            'countryId' => $countryId,
            'checkoutType' => in_array(strtoupper(trim((string) ($context['checkoutType'] ?? 'DOMICILIO'))), ['T', 'TIENDA'], true) ? 'TIENDA' : 'DOMICILIO',
            'storeCode' => $context['storeCode'] ?? null,
            'currencySymbol' => $this->currencySymbol($countryCode),
            'includeUntriggered' => true,
            'lines' => $products->map(fn (object $product) => [
                'key' => (string) $product->pro_id,
                'productId' => (int) $product->pro_id,
                'quantity' => 1,
                'unitPrice' => (float) ($product->display_price ?? $product->ppa_precio),
            ])->all(),
        ]);

        return collect($resolution['lines'])->keyBy('productId');
    }

    private function currencySymbol(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'GT' => 'Q',
            'CR' => '₡',
            'HN' => 'L',
            'DO' => 'RD$',
            default => '$',
        };
    }
}
