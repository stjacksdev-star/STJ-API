<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileCategoryService
{
    public function all(int $countryId): array
    {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages([
                'countryId' => 'Pais no soportado.',
            ]);
        }

        $categories = DB::table('stj_categorias')
            ->where('cat_habilitado_app', 1)
            ->where('cat_id', '<>', 11)
            ->orderBy('cat_orden_app')
            ->get(['cat_id', 'cat_logo_app', 'cat_tallas']);

        $subcategoryGroups = DB::table('stj_sub_categorias')
            ->whereIn('sca_categoria', $categories->pluck('cat_id'))
            ->orderBy('sca_nombre')
            ->get(['sca_id', 'sca_categoria', 'sca_nombre'])
            ->groupBy('sca_categoria');

        $assetUrl = (string) config('mobile.legacy_category_asset_url');

        return $categories->map(static function (object $category) use ($subcategoryGroups, $assetUrl): array {
            $subcategories = $subcategoryGroups->get($category->cat_id, collect())
                ->map(static fn (object $subcategory): array => [
                    'id' => $subcategory->sca_id,
                    'nombre' => $subcategory->sca_nombre,
                ])
                ->values()
                ->all();

            return [
                'id' => $category->cat_id,
                'nombre' => '<span style="color:rgb(0,122,201)">&nbsp;</span>',
                'foto' => $assetUrl.'/'.ltrim((string) $category->cat_logo_app, '/'),
                'tallas' => $category->cat_tallas,
                'subCategorias' => $subcategories,
            ];
        })->values()->all();
    }

    public function search(int $countryId): array
    {
        $this->assertCountryExists($countryId);

        $categories = DB::table('stj_categorias')
            ->where('cat_habilitado_app', 1)
            ->where('cat_id', '<>', 10)
            ->orderBy('cat_orden_app')
            ->get(['cat_id', 'cat_nombre_app', 'cat_logo_app', 'cat_tallas']);

        $subcategoryGroups = $this->subcategoriesFor($categories->pluck('cat_id')->all());
        $assetUrl = (string) config('mobile.legacy_category_asset_url');

        return $categories->map(static function (object $category) use ($subcategoryGroups, $assetUrl): array {
            return [
                'id' => $category->cat_id,
                'nombre' => '<span style="color:rgb(0,122,201)">'.(string) $category->cat_nombre_app.'</span>',
                'foto2' => $assetUrl.'/ocho2/'.$category->cat_id.'.jpg',
                'tallas' => $category->cat_tallas,
                'subCategorias' => $subcategoryGroups->get($category->cat_id, collect())->values()->all(),
                'foto' => $assetUrl.'/'.ltrim((string) $category->cat_logo_app, '/'),
            ];
        })->values()->all();
    }

    private function assertCountryExists(int $countryId): void
    {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages([
                'countryId' => 'Pais no soportado.',
            ]);
        }
    }

    private function subcategoriesFor(array $categoryIds)
    {
        return DB::table('stj_sub_categorias')
            ->whereIn('sca_categoria', $categoryIds)
            ->orderBy('sca_nombre')
            ->get(['sca_id', 'sca_categoria', 'sca_nombre'])
            ->map(static fn (object $subcategory): array => [
                'categoryId' => $subcategory->sca_categoria,
                'id' => $subcategory->sca_id,
                'nombre' => $subcategory->sca_nombre,
            ])
            ->groupBy('categoryId')
            ->map(static fn ($subcategories) => $subcategories->map(static fn (array $subcategory): array => [
                'id' => $subcategory['id'],
                'nombre' => $subcategory['nombre'],
            ]));
    }
}
