<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;

class MobileNewArrivalAssetService
{
    /** @return array{left:array<int, array<string, mixed>>, center:array<int, array<string, mixed>>, right:array<int, array<string, mixed>>} */
    public function forCountry(int $countryId): array
    {
        $groups = ['left' => [], 'center' => [], 'right' => []];

        $assets = DB::table('stj_assets as asset')
            ->leftJoin('stj_promociones as promotion', 'promotion.prm_id', '=', 'asset.ast_idpromocion')
            ->leftJoin('stj_coleccion as collection', 'collection.col_id', '=', 'asset.ast_idpromocion')
            ->where('asset.ast_pais', $countryId)
            ->where('asset.ast_tipo', 'LO-MAS-NUEVO')
            ->where('asset.ast_estado', 'ACTIVO')
            ->whereIn('asset.ast_plataforma', ['TODO', 'APP'])
            ->where(fn ($query) => $query->whereNull('asset.ast_inicio')->orWhere('asset.ast_inicio', '<=', now()))
            ->where(fn ($query) => $query->whereNull('asset.ast_fin')->orWhere('asset.ast_fin', '>=', now()))
            ->where(function ($query) use ($countryId) {
                $query->where(function ($collection) use ($countryId) {
                    $collection->where('asset.ast_tipo_accion', 7)
                        ->whereColumn('collection.col_id', 'asset.ast_idpromocion')
                        ->where('collection.col_pais', $countryId);
                })->orWhere(function ($promotion) use ($countryId) {
                    $promotion->where('asset.ast_tipo_accion', '!=', 7)
                        ->whereColumn('promotion.prm_id', 'asset.ast_idpromocion')
                        ->where('promotion.prm_pais', $countryId)
                        ->where('promotion.prm_estado', 'EN-PROCESO');
                });
            })
            ->orderBy('asset.ast_orden')
            ->orderBy('asset.ast_id')
            ->get([
                'asset.ast_id', 'asset.ast_posicion', 'asset.ast_orden', 'asset.ast_imagen',
                'asset.ast_imagen_movil', 'asset.ast_tipo_accion', 'asset.ast_idpromocion',
                'asset.ast_titulo', 'promotion.prm_nombre', 'promotion.prm_encabezado',
                'collection.col_nombre', 'collection.col_header',
            ]);

        foreach ($assets as $asset) {
            $isCollection = (int) $asset->ast_tipo_accion === 7;
            $position = $this->position((string) $asset->ast_posicion);
            $groups[$position][] = [
                'imagen' => $this->assetUrl($asset->ast_imagen ?: $asset->ast_imagen_movil),
                'accion' => true,
                'tipoAccion' => (int) ($asset->ast_tipo_accion ?? 0),
                'imgHeader' => $this->assetUrl($isCollection ? $asset->col_header : $asset->prm_encabezado),
                'title' => (string) (($isCollection ? $asset->col_nombre : $asset->prm_nombre) ?: $asset->ast_titulo ?: ''),
                'descripcion' => '',
                'promocion' => (int) ($asset->ast_idpromocion ?? 0),
                'categoria' => '',
                'scategoria' => '',
                'URL' => null,
            ];
        }

        return $groups;
    }

    private function position(string $position): string
    {
        return match (strtoupper(trim($position))) {
            'CENTRO', 'CENTER', '2', '02' => 'center',
            'DERECHA', 'RIGHT', '3', '03' => 'right',
            default => 'left',
        };
    }

    private function assetUrl(mixed $path): string
    {
        $path = trim((string) $path);

        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('services.mobile_assets.base_url', 'https://stjacks.com'), '/').'/'.ltrim($path, '/');
    }
}
