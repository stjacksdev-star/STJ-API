<?php

namespace App\Services;

use App\Support\StorefrontImageUrl;
use Illuminate\Support\Facades\DB;

class StorefrontDenimService
{
    private const CATEGORY_ID = 18;

    public function landingBanner(): ?array
    {
        $category = DB::table('stj_categorias')
            ->where('cat_id', self::CATEGORY_ID)
            ->first(['cat_id', 'cat_nombre', 'cat_header']);

        if (! $category) {
            return null;
        }

        return [
            'categoryId' => (int) $category->cat_id,
            'alt' => trim((string) $category->cat_nombre) ?: 'Denim',
            'image' => $this->categoryAsset($category->cat_header),
        ];
    }

    private function categoryAsset(mixed $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/images/') || str_starts_with($path, 'images/')) {
            return StorefrontImageUrl::asset($path);
        }

        $baseUrl = rtrim((string) config('filesystems.disks.spaces.url'), '/')
            ?: 'https://stj-assets.sfo3.cdn.digitaloceanspaces.com';

        return $baseUrl.'/'.(str_starts_with(ltrim($path, '/'), 'categorias/')
            ? ltrim($path, '/')
            : 'categorias/'.ltrim($path, '/'));
    }
}
