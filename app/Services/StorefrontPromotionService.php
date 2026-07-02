<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StorefrontPromotionService
{
    private const STOREFRONT_TIMEZONE = 'America/El_Salvador';

    public function activeForCountry(string $country): array
    {
        $countryCode = strtoupper(trim($country));

        if ($countryCode === '') {
            return [
                'country' => strtolower($country),
                'items' => [],
            ];
        }

        $now = Carbon::now(self::STOREFRONT_TIMEZONE)->format('Y-m-d H:i:s');

        $items = DB::table('stj_promociones as p')
            ->join('stj_paises as c', 'c.pai_id', '=', 'p.prm_pais')
            ->join('stj_promociones_horario as h', 'h.pho_promocion', '=', 'p.prm_id')
            ->whereRaw('UPPER(c.pai_codigo) = ?', [$countryCode])
            ->where('p.prm_estado', 'EN-PROCESO')
            ->where('h.pho_tipo', 'NORMAL')
            ->where('h.pho_inicio', '<=', $now)
            ->where('h.pho_fin', '>=', $now)
            ->whereIn('h.pho_estado', ['ACTIVO', 'PENDIENTE'])
            ->select([
                'p.prm_id',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
                'p.prm_tipo_promocion',
                'h.pho_inicio',
                'h.pho_fin',
            ])
            ->orderBy('h.pho_fin')
            ->orderByDesc('p.prm_id')
            ->limit(12)
            ->get()
            ->map(fn (object $promotion) => $this->normalizePromotion($promotion))
            ->filter(fn (array $promotion) => $promotion['title'] !== '')
            ->values()
            ->all();

        return [
            'country' => strtolower($countryCode),
            'items' => $items,
        ];
    }

    private function normalizePromotion(object $promotion): array
    {
        $title = trim((string) ($promotion->prm_nombre_comercial ?: $promotion->prm_nombre));

        return [
            'id' => (int) $promotion->prm_id,
            'title' => $title,
            'type' => 'promo',
            'promotionType' => trim((string) $promotion->prm_tipo_promocion),
            'href' => "Promociones/?idPromocion={$promotion->prm_id}&Promo",
            'startAt' => $promotion->pho_inicio,
            'endAt' => $promotion->pho_fin,
        ];
    }
}
