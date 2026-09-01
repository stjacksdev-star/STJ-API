<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;

class MobileBannerAssetService
{
    /** @return array<int, array<string, mixed>> */
    public function forCountry(int $countryId): array
    {
        return DB::table('stj_assets')
            ->where('ast_pais', $countryId)
            ->where('ast_tipo', 'BANNER')
            ->where('ast_estado', 'ACTIVO')
            ->whereIn('ast_plataforma', ['TODO', 'APP'])
            ->where(fn ($query) => $query->whereNull('ast_tipo_accion')->orWhere('ast_tipo_accion', '<>', 4))
            ->where(fn ($query) => $query->whereNull('ast_inicio')->orWhere('ast_inicio', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ast_fin')->orWhere('ast_fin', '>=', now()))
            ->orderBy('ast_orden')
            ->orderBy('ast_id')
            ->get([
                'ast_id', 'ast_imagen_movil', 'ast_imagen', 'ast_tipo_accion',
                'ast_idpromocion', 'ast_titulo',
            ])
            ->map(function (object $asset): array {
                $image = $this->assetUrl($asset->ast_imagen_movil ?: $asset->ast_imagen);
                $actionType = (int) ($asset->ast_tipo_accion ?? 0);

                return [
                    'banner' => $image,
                    'accion' => $actionType > 0,
                    'tipoAccion' => $actionType,
                    'imgHeader' => $image,
                    'title' => (string) ($asset->ast_titulo ?? ''),
                    'descripcion' => '',
                    'promocion' => (int) ($asset->ast_idpromocion ?? 0),
                    'categoria' => null,
                    'scategoria' => null,
                    'URL' => null,
                ];
            })
            ->values()
            ->all();
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
