<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssetPublicationService
{
    private const TYPES = [
        'CUPON',
        'LO-MAS-NUEVO',
        'BANNER',
        'SLIDER',
    ];

    /**
     * @return array<string, mixed>
     */
    public function publish(?Carbon $now = null): array
    {
        $now ??= now();

        $finished = DB::table('stj_assets')
            ->where('ast_estado', 'ACTIVO')
            ->where('ast_fin', '<', $now->toDateTimeString())
            ->update(['ast_estado' => 'FINALIZADO']);

        $activated = DB::table('stj_assets')
            ->where('ast_estado', 'PENDIENTE')
            ->where('ast_inicio', '<=', $now->toDateTimeString())
            ->update(['ast_estado' => 'ACTIVO']);

        $countries = $this->countries();
        $payload = [
            'generatedAt' => $now->toIso8601String(),
            'summary' => [
                'finished' => $finished,
                'activated' => $activated,
                'countries' => count($countries),
                'types' => self::TYPES,
            ],
            'countries' => [],
        ];

        foreach ($countries as $country) {
            $countryCode = strtolower((string) $country->pai_codigo);
            $payload['countries'][$countryCode] = [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
                'assets' => $this->assetsByType((int) $country->pai_id),
            ];
        }

        $path = $this->writeJson($payload);
        $payload['summary']['path'] = $path;

        return $payload;
    }

    /**
     * @return array<int, object>
     */
    private function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->whereIn('pai_codigo', ['SV', 'GT', 'CR', 'PA', 'DO', 'HN'])
            ->orderByRaw("FIELD(pai_codigo, 'SV', 'GT', 'CR', 'PA', 'DO', 'HN')")
            ->get()
            ->all();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function assetsByType(int $countryId): array
    {
        $assets = [];

        foreach (self::TYPES as $type) {
            $assets[strtolower($type)] = DB::table('stj_assets')
                ->where('ast_tipo', $type)
                ->where('ast_estado', 'ACTIVO')
                ->where('ast_pais', $countryId)
                ->whereIn('ast_plataforma', ['TODO', 'WEB'])
                ->orderBy('ast_orden')
                ->orderBy('ast_id')
                ->get()
                ->map(fn ($asset) => $this->normalizeAsset($asset))
                ->values()
                ->all();
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAsset(object $asset): array
    {
        $type = strtoupper((string) $asset->ast_tipo);
        $base = [
            'id' => (int) $asset->ast_id,
            'type' => $type,
            'platform' => $asset->ast_plataforma,
            'order' => $asset->ast_orden !== null ? (int) $asset->ast_orden : null,
            'image' => $this->cleanValue($asset->ast_imagen ?? null),
            'mobileImage' => $this->cleanValue($asset->ast_imagen_movil ?? null),
            'link' => $this->cleanValue($asset->ast_link ?? null),
            'position' => $this->cleanValue($asset->ast_posicion ?? null),
            'title' => $this->cleanValue($asset->ast_titulo ?? null),
            'startAt' => $asset->ast_inicio,
            'endAt' => $asset->ast_fin,
        ];

        return match ($type) {
            'CUPON' => [
                ...$base,
                'legacyLine' => implode('|', [$base['image'], $base['link']]),
            ],
            'LO-MAS-NUEVO' => [
                ...$base,
                'legacyLine' => implode('|', [$base['image'], $base['link'], $base['position']]),
            ],
            'BANNER', 'SLIDER' => [
                ...$base,
                'desktopImage' => $base['image'],
                'legacyLine' => implode('|', [$base['image'], $base['mobileImage'], $base['link']]),
            ],
            default => $base,
        };
    }

    private function cleanValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(array $payload): string
    {
        $path = storage_path('app/storefront/assets.json');
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $temporaryPath = $path.'.tmp';
        file_put_contents($temporaryPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($temporaryPath, $path);

        return $path;
    }
}
