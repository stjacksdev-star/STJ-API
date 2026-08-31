<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class StorefrontNavigationService
{
    private const VERSION = 1;

    private const GROUPS = [
        ['key' => 'girls', 'label' => 'Niñas', 'categoryIds' => [5]],
        ['key' => 'boys', 'label' => 'Niños', 'categoryIds' => [6]],
        ['key' => 'toddlers', 'label' => 'Toddlers', 'categoryIds' => [3, 4]],
        ['key' => 'babies', 'label' => 'Bebés', 'categoryIds' => [1, 16, 2]],
        ['key' => 'adults', 'label' => 'Adultos', 'categoryIds' => [7, 8]],
        ['key' => 'teens', 'label' => 'Juvenil', 'categoryIds' => [14, 13]],
        [
            'key' => 'accessories',
            'label' => 'Accesorios',
            'categoryIds' => [9, 10],
            'categoryNames' => ['Ropa Interior y Accesorios', 'Cuidado Personal', 'Otros'],
        ],
    ];

    public function get(string $countryCode): ?array
    {
        $countryCode = strtolower(trim($countryCode));
        $key = $this->cacheKey($countryCode);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $snapshot = $this->readSnapshot($countryCode);

        if ($snapshot) {
            Cache::put($key, $snapshot, now()->addDay());
        }

        return $snapshot;
    }

    public function getOrBuild(string $countryCode): ?array
    {
        return $this->get($countryCode) ?? $this->build($countryCode);
    }

    public function build(string $countryCode): ?array
    {
        $countryCode = strtolower(trim($countryCode));
        $country = DB::table('stj_paises')
            ->where('pai_codigo', strtoupper($countryCode))
            ->first(['pai_id', 'pai_codigo', 'pai_nombre']);

        if (! $country) {
            return null;
        }

        $cutoff = now()->subDays(30);
        $rows = DB::table('stj_producto_pais as pp')
            ->join('stj_productos as p', 'p.pro_id', '=', 'pp.ppa_producto')
            ->join('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->join('stj_sub_categorias as sc', 'sc.sca_id', '=', 'p.pro_sub_categoria')
            ->where('pp.ppa_pais', $country->pai_id)
            ->where('pp.ppa_estado', 'ACTIVO')
            ->where('p.pro_estatus', 'ACTIVO')
            ->where(function ($query) {
                $query->whereIn('c.cat_id', $this->categoryIds())
                    ->orWhereIn('c.cat_nombre', $this->categoryNames());
            })
            ->select([
                'c.cat_id',
                'c.cat_codigo',
                'c.cat_nombre',
                'sc.sca_id',
                'sc.sca_nombre',
                DB::raw('COUNT(DISTINCT p.pro_id) as product_count'),
                DB::raw('MAX(pp.ppa_fecha_activo) as latest_activation'),
            ])
            ->groupBy('c.cat_id', 'c.cat_codigo', 'c.cat_nombre', 'sc.sca_id', 'sc.sca_nombre')
            ->orderBy('c.cat_id')
            ->orderBy('sc.sca_nombre')
            ->get();

        $byCategory = $rows->groupBy(fn (object $row) => (int) $row->cat_id);
        $groups = collect(self::GROUPS)
            ->map(function (array $definition) use ($byCategory, $cutoff): array {
                $categoryIds = collect($definition['categoryIds'])
                    ->merge(
                        $byCategory->flatten(1)
                            ->whereIn('cat_nombre', $definition['categoryNames'] ?? [])
                            ->pluck('cat_id')
                    )
                    ->map(fn ($id) => (int) $id)
                    ->unique();
                $segments = $categoryIds
                    ->map(function (int $categoryId) use ($byCategory, $cutoff): ?array {
                        $rows = $byCategory->get($categoryId, collect());
                        $first = $rows->first();

                        if (! $first) {
                            return null;
                        }

                        $subcategories = $rows->map(function (object $row) use ($cutoff): array {
                            $latest = $row->latest_activation ? Carbon::parse($row->latest_activation) : null;

                            return [
                                'id' => (int) $row->sca_id,
                                'label' => trim((string) $row->sca_nombre),
                                'productCount' => (int) $row->product_count,
                                'isNew' => $latest?->greaterThanOrEqualTo($cutoff) ?? false,
                            ];
                        })->values();

                        return [
                            'categoryId' => (int) $first->cat_id,
                            'categoryCode' => trim((string) $first->cat_codigo),
                            'label' => trim((string) $first->cat_nombre),
                            'productCount' => $subcategories->sum('productCount'),
                            'subcategories' => $subcategories->all(),
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'productCount' => $segments->sum('productCount'),
                    'segments' => $segments->all(),
                ];
            })
            ->filter(fn (array $group) => $group['segments'] !== [])
            ->values()
            ->all();

        $payload = [
            'version' => self::VERSION,
            'country' => strtolower((string) $country->pai_codigo),
            'countryName' => trim((string) $country->pai_nombre),
            'generatedAt' => now()->toIso8601String(),
            'groups' => $groups,
        ];

        $this->writeSnapshot($countryCode, $payload);
        Cache::put($this->cacheKey($countryCode), $payload, now()->addDay());

        return $payload;
    }

    public function supportedCountryCodes(): array
    {
        return DB::table('stj_paises')
            ->whereIn('pai_codigo', ['SV', 'GT', 'CR', 'PA', 'HN'])
            ->orderBy('pai_id')
            ->pluck('pai_codigo')
            ->map(fn ($code) => strtolower((string) $code))
            ->all();
    }

    private function categoryIds(): array
    {
        return collect(self::GROUPS)->pluck('categoryIds')->flatten()->unique()->values()->all();
    }

    private function categoryNames(): array
    {
        return collect(self::GROUPS)->pluck('categoryNames')->flatten()->filter()->unique()->values()->all();
    }

    private function cacheKey(string $countryCode): string
    {
        return 'storefront:navigation:v'.self::VERSION.':'.$countryCode;
    }

    private function snapshotPath(string $countryCode): string
    {
        return storage_path('app/storefront/navigation/'.$countryCode.'.json');
    }

    private function readSnapshot(string $countryCode): ?array
    {
        $path = $this->snapshotPath($countryCode);

        if (! File::isFile($path)) {
            return null;
        }

        $payload = json_decode((string) File::get($path), true);

        return is_array($payload) && ($payload['version'] ?? null) === self::VERSION ? $payload : null;
    }

    private function writeSnapshot(string $countryCode, array $payload): void
    {
        $path = $this->snapshotPath($countryCode);
        $directory = dirname($path);
        File::ensureDirectoryExists($directory);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        File::replace($path, $json);
    }
}
