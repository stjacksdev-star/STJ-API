<?php

namespace App\Services;

use Illuminate\Support\Arr;

class StorefrontHomeService
{
    private const LEGACY_HOST = 'https://stjacks.com';

    public function forCountry(string $country): array
    {
        $country = strtolower($country);
        $payload = $this->readPayload($country);

        return [
            'country' => $country,
            'heroSlides' => $this->mapHeroSlides(Arr::get($payload, 'hero_slides', [])),
            'banners' => $this->mapBanners(Arr::get($payload, 'banners', [])),
            'newArrivals' => [
                'left' => $this->mapPromoItems(Arr::get($payload, 'new_arrivals.left', [])),
                'center' => $this->mapPromoItems(Arr::get($payload, 'new_arrivals.center', [])),
                'right' => $this->mapPromoItems(Arr::get($payload, 'new_arrivals.right', [])),
            ],
            'bestSellers' => $this->mapBestSellers(Arr::get($payload, 'best_sellers', [])),
        ];
    }

    private function readPayload(string $country): array
    {
        $path = resource_path("storefront/home/{$country}.json");

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : [];
    }

    private function mapHeroSlides(array $slides): array
    {
        return collect($slides)
            ->map(fn (array $slide) => [
                'desktopImage' => $this->assetUrl(Arr::get($slide, 'desktop')),
                'mobileImage' => $this->assetUrl(Arr::get($slide, 'mobile')),
                'href' => $this->linkUrl(Arr::get($slide, 'link')),
            ])
            ->filter(fn (array $slide) => $slide['desktopImage'] || $slide['mobileImage'])
            ->values()
            ->all();
    }

    private function mapBanners(array $banners): array
    {
        return collect($banners)
            ->map(fn (array $banner) => [
                'desktopImage' => $this->assetUrl(Arr::get($banner, 'desktop')),
                'mobileImage' => $this->assetUrl(Arr::get($banner, 'mobile')),
                'href' => $this->linkUrl(Arr::get($banner, 'link')),
            ])
            ->filter(fn (array $banner) => $banner['desktopImage'] || $banner['mobileImage'])
            ->values()
            ->all();
    }

    private function mapPromoItems(array $items): array
    {
        return collect($items)
            ->map(fn (array $item) => [
                'image' => $this->assetUrl(Arr::get($item, 'image')),
                'href' => $this->linkUrl(Arr::get($item, 'link')),
            ])
            ->filter(fn (array $item) => $item['image'])
            ->values()
            ->all();
    }

    private function mapBestSellers(array $items): array
    {
        return collect($items)
            ->map(fn (array $item) => [
                'id' => (int) Arr::get($item, 'pro_id'),
                'sku' => trim((string) Arr::get($item, 'pro_codigo', '')),
                'name' => trim((string) Arr::get($item, 'pro_nombre', '')),
                'brand' => trim((string) Arr::get($item, 'pro_marca', '')),
                'category' => trim((string) Arr::get($item, 'cat_nombre', '')),
                'subcategory' => trim((string) Arr::get($item, 'sca_nombre', '')),
                'price' => (float) Arr::get($item, 'ppa_precio', 0),
                'promoName' => trim((string) Arr::get($item, 'ppa_promo_nombre', '')),
                'isPopular' => (string) Arr::get($item, 'ppa_es_popular') === '1',
                'imageUrl' => $this->productImageUrl(Arr::get($item, 'pro_thumbs')),
            ])
            ->filter(fn (array $item) => $item['id'] > 0 && $item['name'] !== '')
            ->values()
            ->all();
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        return self::LEGACY_HOST.$this->normalizePath($path);
    }

    private function productImageUrl(?string $filename): ?string
    {
        if (! $filename) {
            return null;
        }

        return self::LEGACY_HOST.'/images/p400/'.ltrim($filename, '/');
    }

    private function linkUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        return self::LEGACY_HOST.$this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $cleanPath = preg_replace('#/+#', '/', '/'.ltrim($path, '/'));

        return is_string($cleanPath) ? $cleanPath : '/';
    }

    private function isAbsoluteUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }
}
