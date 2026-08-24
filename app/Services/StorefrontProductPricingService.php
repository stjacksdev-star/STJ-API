<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StorefrontProductPricingService
{
    public function resolve(int $countryId, int $productId, string $sku, string $size, mixed $at = null): array
    {
        $product = DB::table('stj_productos as p')
            ->join('stj_producto_pais as pp', 'pp.ppa_producto', '=', 'p.pro_id')
            ->where('p.pro_id', $productId)->where('p.pro_codigo', trim($sku))
            ->where('p.pro_estatus', 'ACTIVO')->where('pp.ppa_estado', 'ACTIVO')
            ->where('pp.ppa_pais', $countryId)
            ->first(['p.pro_id', 'p.pro_codigo', 'p.pro_nombre', 'p.pro_tallas', 'pp.ppa_id', 'pp.ppa_precio', 'pp.ppa_precio_talla']);

        if (! $product) {
            return $this->rejected('PRODUCT_UNAVAILABLE', 'Producto/SKU inactivo o no disponible para el pais.');
        }
        $size = trim($size);
        $sizes = collect(explode(',', (string) $product->pro_tallas))->map(fn ($value) => trim($value));
        if (! $sizes->contains($size)) {
            return $this->rejected('SIZE_INVALID', 'La talla no pertenece al producto.');
        }

        $bySize = strtoupper(trim((string) $product->ppa_precio_talla)) === 'SI';
        $priceId = (int) $product->ppa_id;
        $origin = 'GENERAL';
        $regularCents = $this->toCents($product->ppa_precio);

        if ($bySize) {
            $rows = DB::table('stj_producto_talla')->where('pta_pais', $countryId)
                ->where('pta_producto', $productId)->where('pta_talla', $size)
                ->get(['pta_id', 'pta_precio']);
            if ($rows->count() !== 1) {
                return $this->rejected('SIZE_PRICE_UNAVAILABLE', $rows->isEmpty() ? 'La talla seleccionada no tiene un precio valido.' : 'La talla seleccionada tiene precios duplicados.');
            }
            $priceId = (int) $rows->first()->pta_id;
            $regularCents = $this->toCents($rows->first()->pta_precio);
            $origin = 'TALLA';
        }
        if ($regularCents <= 0) {
            return $this->rejected('PRICE_INVALID', 'El producto no tiene un precio comercial valido.');
        }

        return [
            'ok' => true, 'reason' => null, 'alerts' => [],
            'productId' => (int) $product->pro_id, 'sku' => trim((string) $product->pro_codigo),
            'name' => trim((string) $product->pro_nombre), 'size' => $size,
            'precio_regular' => $this->decimal($regularCents), 'origen_precio' => $origin,
            'precio_registro_id' => $priceId, 'descuento' => '0.00',
            'descuento_porcentaje' => '0.00',
            'precio_final' => $this->decimal($regularCents),
            'promocion' => null,
            'moneda' => $this->currency($countryId),
        ];
    }

    private function rejected(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message, 'alerts' => [['type' => $reason, 'message' => $message]]];
    }

    private function toCents(mixed $value): int
    {
        return $this->scaledInteger($value, 2);
    }

    private function scaledInteger(mixed $value, int $scale): int
    {
        $raw = trim((string) $value);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $raw, $matches)) {
            return 0;
        }
        $factor = 10 ** $scale;
        $fraction = str_pad($matches[3] ?? '', $scale + 1, '0');
        $result = ((int) $matches[2] * $factor) + (int) substr($fraction, 0, $scale);
        if ((int) ($fraction[$scale] ?? '0') >= 5) {
            $result++;
        }

        return ($matches[1] ?? '') === '-' ? -$result : $result;
    }

    private function decimal(int $value, int $scale = 2): string
    {
        $factor = 10 ** $scale;
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);

        return $sign.intdiv($value, $factor).'.'.str_pad((string) ($value % $factor), $scale, '0', STR_PAD_LEFT);
    }

    private function currency(int $countryId): string
    {
        $code = strtolower((string) DB::table('stj_paises')->where('pai_id', $countryId)->value('pai_codigo'));

        return ['gt' => 'GTQ', 'cr' => 'CRC', 'pa' => 'USD', 'do' => 'DOP', 'hn' => 'HNL'][$code] ?? 'USD';
    }
}
