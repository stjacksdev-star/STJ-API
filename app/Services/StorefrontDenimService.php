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

    public function landing(): array
    {
        $content = config('storefront_denim');
        $baseUrl = rtrim((string) ($content['asset_base_url'] ?? ''), '/');

        return [
            'banner' => array_merge($content['banner'] ?? [], $this->landingBanner() ?? []),
            'heroSlider' => $this->decorateItems($content['hero_slider'] ?? [], $baseUrl),
            'featuredFits' => [
                'title' => $content['featured_fits']['title'] ?? 'Fits destacados',
                'items' => $this->decorateItems($content['featured_fits']['items'] ?? [], $baseUrl),
            ],
            'details' => [
                'title' => $content['details']['title'] ?? 'Detalles',
                'columns' => array_map(fn (array $column) => $this->decorateItems($column, $baseUrl), $content['details']['columns'] ?? []),
            ],
            'video' => $this->decorateAssets($content['video'] ?? [], $baseUrl),
            'audienceLinks' => $content['audience_links'] ?? [],
        ];
    }

    private function decorateItems(array $items, string $baseUrl): array
    {
        return array_map(fn (array $item) => $this->decorateAssets($item, $baseUrl), $items);
    }

    private function decorateAssets(array $item, string $baseUrl): array
    {
        foreach (['desktopImage', 'mobileImage', 'srcDesktop', 'srcMobile', 'posterDesktop', 'posterMobile'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '' && ! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
                $item[$key] = $baseUrl.'/'.ltrim($value, '/');
            }
        }

        return $item;
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
