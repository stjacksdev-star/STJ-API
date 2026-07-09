<?php

namespace App\Services;

class StorefrontAssetService
{
    public function forCountry(string $country): array
    {
        $country = strtolower($country);
        $payload = $this->readPayload();
        $countryPayload = is_array($payload['countries'][$country] ?? null)
            ? $payload['countries'][$country]
            : [];
        $assets = is_array($countryPayload['assets'] ?? null) ? $countryPayload['assets'] : [];

        return [
            'country' => $country,
            'generatedAt' => $payload['generatedAt'] ?? null,
            'heroSlides' => $this->mapSlider($assets['slider'] ?? []),
            'banners' => $this->mapBanners($assets['banner'] ?? []),
            'newArrivals' => $this->mapNewArrivals($assets['lo-mas-nuevo'] ?? []),
        ];
    }

    private function readPayload(): array
    {
        $path = storage_path('app/storefront/assets.json');

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : [];
    }

    private function mapSlider(array $assets): array
    {
        return collect($assets)
            ->sortBy([
                fn (array $asset) => (int) ($asset['order'] ?? 0),
                fn (array $asset) => (int) ($asset['id'] ?? 0),
            ])
            ->map(fn (array $asset) => [
                'imagen' => $this->assetUrl($asset['ast_imagen'] ?? $asset['imagen'] ?? $asset['desktopImage'] ?? $asset['image'] ?? null),
                'imagen_movil' => $this->assetUrl($asset['ast_imagen_movil'] ?? $asset['imagen_movil'] ?? $asset['mobileImage'] ?? null),
                'desktopImage' => $this->assetUrl($asset['ast_imagen'] ?? $asset['imagen'] ?? $asset['desktopImage'] ?? $asset['image'] ?? null),
                'mobileImage' => $this->assetUrl($asset['ast_imagen_movil'] ?? $asset['imagen_movil'] ?? $asset['mobileImage'] ?? null),
                'href' => $this->linkUrl($asset['link'] ?? null),
            ])
            ->filter(fn (array $asset) => $asset['imagen'] || $asset['imagen_movil'])
            ->values()
            ->all();
    }

    private function mapBanners(array $assets): array
    {
        return collect($assets)
            ->sortBy([
                fn (array $asset) => (int) ($asset['order'] ?? 0),
                fn (array $asset) => (int) ($asset['id'] ?? 0),
            ])
            ->map(fn (array $asset) => [
                'imagen' => $this->assetUrl($asset['ast_imagen'] ?? $asset['imagen'] ?? $asset['desktopImage'] ?? $asset['image'] ?? null),
                'imagen_movil' => $this->assetUrl($asset['ast_imagen_movil'] ?? $asset['imagen_movil'] ?? $asset['mobileImage'] ?? null),
                'desktopImage' => $this->assetUrl($asset['ast_imagen'] ?? $asset['imagen'] ?? $asset['desktopImage'] ?? $asset['image'] ?? null),
                'mobileImage' => $this->assetUrl($asset['ast_imagen_movil'] ?? $asset['imagen_movil'] ?? $asset['mobileImage'] ?? null),
                'href' => $this->linkUrl($asset['link'] ?? null),
            ])
            ->filter(fn (array $asset) => $asset['imagen'] || $asset['imagen_movil'])
            ->values()
            ->all();
    }

    private function mapNewArrivals(array $assets): array
    {
        $columns = [
            'left' => [],
            'center' => [],
            'right' => [],
        ];

        collect($assets)
            ->sortBy([
                fn (array $asset) => $this->positionRank($asset['position'] ?? null),
                fn (array $asset) => (int) ($asset['order'] ?? 0),
                fn (array $asset) => (int) ($asset['id'] ?? 0),
            ])
            ->each(function (array $asset) use (&$columns) {
                $column = $this->positionColumn($asset['position'] ?? null);
                $image = $this->assetUrl($asset['image'] ?? null);

                if (! $image) {
                    return;
                }

                $columns[$column][] = [
                    'image' => $image,
                    'href' => $this->linkUrl($asset['link'] ?? null),
                    'position' => $asset['position'] ?? null,
                    'order' => $asset['order'] ?? null,
                ];
            });

        return $columns;
    }

    private function positionColumn(mixed $position): string
    {
        return match ($this->positionRank($position)) {
            2 => 'center',
            3 => 'right',
            default => 'left',
        };
    }

    private function positionRank(mixed $position): int
    {
        $position = strtoupper(trim((string) $position));

        return match ($position) {
            '2', '02', 'CENTER', 'CENTRO', 'DROP 02', 'DROP02' => 2,
            '3', '03', 'RIGHT', 'DERECHA', 'DROP 03', 'DROP03' => 3,
            default => 1,
        };
    }

    private function assetUrl(mixed $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('/'.ltrim($path, '/'));
    }

    private function linkUrl(mixed $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $path;
    }
}
