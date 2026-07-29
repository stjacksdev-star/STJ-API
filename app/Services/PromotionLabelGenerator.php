<?php

namespace App\Services;

class PromotionLabelGenerator
{
    /**
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $context
     * @return array<string, string|null>
     */
    public function generate(array $promotion, array $context): array
    {
        $symbol = (string) ($context['currencySymbol'] ?? '$');
        $type = (string) $promotion['promotionType'];
        $restriction = (string) ($promotion['restriction'] ?? '');
        $percentage = (float) ($promotion['percentage'] ?? 0);
        $price = $promotion['price'] ?? null;

        $benefit = match (true) {
            in_array($type, ['DESCUENTO', 'DESCUENTO-SKU'], true) && $percentage > 0 => $this->number($percentage).'% de descuento',
            $type === 'PUNTO-PRECIO' && $price !== null => 'Llévatelo por '.$symbol.$this->number((float) $price),
            $type === 'CONDICION-SKU' && $restriction === '2x1' => 'Aplica 2x1',
            $type === 'CONDICION-SKU' && $restriction === '21/2' => 'Segundo producto a mitad de precio',
            $type === 'CONDICION-SKU' && $restriction === '2doPrecio' && $price !== null => 'Segundo producto a '.$symbol.$this->number((float) $price),
            $type === 'CONDICION-SKU' && $restriction === '2xPP' && $price !== null => '2 por '.$symbol.$this->number((float) $price),
            default => trim((string) ($promotion['commercialName'] ?? $promotion['name'] ?? 'Promoción')),
        };

        $scope = $this->scopeLabel($promotion, $context);

        return [
            'benefitLabel' => $benefit,
            'scopeLabel' => $scope,
            'displayLabel' => $benefit,
        ];
    }

    /**
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $context
     */
    private function scopeLabel(array $promotion, array $context): ?string
    {
        $checkoutType = strtoupper((string) $promotion['checkoutType']);
        $storeScope = strtoupper((string) ($promotion['storeScope'] ?? ''));

        if ($checkoutType === 'D') {
            return 'Oferta online';
        }

        if ($checkoutType === 'T' && $storeScope === 'SELECCIONADAS') {
            $storeName = trim((string) ($context['storeName'] ?? ''));

            return $storeName !== ''
                ? 'Oferta exclusiva en '.$storeName
                : 'Disponible en tiendas seleccionadas';
        }

        if ($checkoutType === 'T' && $storeScope === 'TODAS') {
            return 'Disponible en tiendas';
        }

        return null;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
