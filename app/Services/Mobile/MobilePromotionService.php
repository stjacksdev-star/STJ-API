<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;

class MobilePromotionService
{
    /** @return array<int, array<string, mixed>> */
    public function forCountry(int $countryId): array
    {
        return DB::table('stj_assets as asset')
            ->leftJoin('stj_promociones as promotion', 'promotion.prm_id', '=', 'asset.ast_idpromocion')
            ->where('asset.ast_pais', $countryId)
            ->where('asset.ast_tipo', 'CUPON')
            ->where('asset.ast_estado', 'ACTIVO')
            ->where(function ($query) {
                $query->whereNull('asset.ast_inicio')->orWhere('asset.ast_inicio', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('asset.ast_fin')->orWhere('asset.ast_fin', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('asset.ast_idpromocion')
                    ->orWhere('asset.ast_idpromocion', 0)
                    ->orWhere('promotion.prm_estado', 'EN-PROCESO');
            })
            ->orderBy('asset.ast_orden')
            ->orderBy('asset.ast_id')
            ->get([
                'asset.ast_id',
                'asset.ast_idpromocion',
                'asset.ast_tipo_accion',
                'asset.ast_titulo',
                'asset.ast_imagen',
                'asset.ast_imagen_movil',
                'asset.ast_link',
                'asset.ast_estado',
                'promotion.prm_nombre',
            ])
            ->map(fn (object $asset) => $this->payload($asset))
            ->filter(fn (array $asset) => $asset['imagen'] !== null)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(object $asset): array
    {
        $link = trim((string) ($asset->ast_link ?? ''));
        $promotionId = (int) ($asset->ast_idpromocion ?? 0);
        $collectionId = null;

        if ($promotionId === 0 && preg_match('~Promociones/?\?idPromocion=(\d+)~i', $link, $matches)) {
            $promotionId = (int) $matches[1];
        }

        if (preg_match('~/Productos/Colecciones/?\?id=(\d+)~i', $link, $matches)) {
            $collectionId = (int) $matches[1];
        }

        $actionType = $collectionId ? 7 : 1;
        $image = $asset->ast_imagen_movil ?: $asset->ast_imagen;

        return [
            'id' => $promotionId ?: (int) $asset->ast_id,
            'assetId' => (int) $asset->ast_id,
            'nombre' => $asset->ast_titulo ?: $asset->prm_nombre ?: 'Promocion STJacks',
            'imagen' => $this->assetUrl($image),
            'estado' => (string) $asset->ast_estado,
            'tipoAccion' => $actionType,
            'promocion' => $promotionId ?: null,
            'coleccion' => $collectionId,
            'link' => $link !== '' ? $link : null,
        ];
    }

    private function assetUrl(mixed $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : url('/'.ltrim($path, '/'));
    }
}
