<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;

class MobileLifestyleAssetService
{
    /** @return array<int, array<string, mixed>> */
    public function forCountry(int $countryId): array
    {
        return DB::table('stj_assets')
            ->where('ast_pais', $countryId)
            ->where('ast_tipo', 'SLIDER')
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
            ->map(fn (object $asset): array => [
                'slide' => $this->assetUrl($asset->ast_imagen_movil ?: $asset->ast_imagen),
                'accion' => true,
                'tipoAccion' => (int) ($asset->ast_tipo_accion ?? 0),
                'promocion' => (int) ($asset->ast_idpromocion ?? 0),
                'imgHeader' => '',
                'title' => (string) ($asset->ast_titulo ?? ''),
                'descripcion' => '',
                'categoria' => '',
                'scategoria' => '',
                'URL' => '',
            ])
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
