<?php

namespace App\Services\Dashboard;

use App\Services\Media\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollectionService
{
    public function __construct(
        private readonly ProductCodeImportService $imports,
        private readonly ImageOptimizer $images,
    ) {
    }

    public function index(?string $country = null, int $limit = 200): array
    {
        $countries = $this->countries();
        $countryId = $this->resolveCountryId($country);

        $query = DB::table('stj_coleccion as c')
            ->leftJoin('stj_paises as p', 'p.pai_id', '=', 'c.col_pais')
            ->select([
                'c.col_id',
                'c.col_pais',
                'c.col_uuid',
                'c.col_nombre',
                'c.col_titulo',
                'c.col_header',
                'c.col_posicion_movil',
                'c.col_codigos',
                'c.col_fecha_creado',
                'p.pai_codigo',
                'p.pai_nombre',
            ])
            ->when($countryId !== null, fn ($builder) => $builder->where('c.col_pais', $countryId))
            ->orderByDesc('c.col_fecha_creado')
            ->orderByDesc('c.col_id')
            ->limit(max(1, min($limit, 500)));

        return [
            'filters' => [
                'country' => $countryId,
                'countryCode' => $country ? strtoupper($country) : null,
                'limit' => $limit,
            ],
            'countries' => $countries,
            'collections' => $query
                ->get()
                ->map(fn ($collection) => $this->normalizeCollection($collection))
                ->values()
                ->all(),
        ];
    }

    private function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_nombre')
            ->get()
            ->map(fn ($country) => [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, UploadedFile $banner, UploadedFile $products): array
    {
        $country = $this->resolveCountry($data['country'] ?? null);
        $codes = $this->imports->read($products);
        $headerPath = $this->storeBanner($banner, (string) $data['name']);

        $id = DB::table('stj_coleccion')->insertGetId([
            'col_pais' => $country['id'],
            'col_uuid' => $country['code'].Str::uuid()->toString(),
            'col_nombre' => trim((string) $data['name']),
            'col_titulo' => trim((string) $data['title']),
            'col_header' => $headerPath,
            'col_posicion_movil' => $data['mobilePosition'] ?? 'right',
            'col_codigos' => implode(',', $codes),
            'col_fecha_creado' => now(),
        ]);

        $collection = DB::table('stj_coleccion as c')
            ->leftJoin('stj_paises as p', 'p.pai_id', '=', 'c.col_pais')
            ->select([
                'c.col_id',
                'c.col_pais',
                'c.col_uuid',
                'c.col_nombre',
                'c.col_titulo',
                'c.col_header',
                'c.col_posicion_movil',
                'c.col_codigos',
                'c.col_fecha_creado',
                'p.pai_codigo',
                'p.pai_nombre',
            ])
            ->where('c.col_id', $id)
            ->first();

        return $this->normalizeCollection($collection);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, ?UploadedFile $banner = null, ?UploadedFile $products = null): array
    {
        $existing = DB::table('stj_coleccion')->where('col_id', $id)->first();

        if (! $existing) {
            throw ValidationException::withMessages([
                'collection' => 'La coleccion seleccionada no existe.',
            ]);
        }

        $country = $this->resolveCountry($data['country'] ?? null);
        $updates = [
            'col_pais' => $country['id'],
            'col_nombre' => trim((string) $data['name']),
            'col_titulo' => trim((string) $data['title']),
            'col_posicion_movil' => $data['mobilePosition'] ?? $existing->col_posicion_movil ?? 'right',
        ];

        if ($banner) {
            $updates['col_header'] = $this->withCacheVersion(
                $this->storeBanner($banner, (string) $data['name']),
            );
        }

        if ($products) {
            $updates['col_codigos'] = implode(',', $this->imports->read($products));
        }

        DB::table('stj_coleccion')->where('col_id', $id)->update($updates);

        return $this->find($id);
    }

    private function find(int $id): array
    {
        $collection = DB::table('stj_coleccion as c')
            ->leftJoin('stj_paises as p', 'p.pai_id', '=', 'c.col_pais')
            ->select([
                'c.col_id',
                'c.col_pais',
                'c.col_uuid',
                'c.col_nombre',
                'c.col_titulo',
                'c.col_header',
                'c.col_posicion_movil',
                'c.col_codigos',
                'c.col_fecha_creado',
                'p.pai_codigo',
                'p.pai_nombre',
            ])
            ->where('c.col_id', $id)
            ->first();

        return $this->normalizeCollection($collection);
    }

    /**
     * @return array{id: int, code: string}
     */
    private function resolveCountry(mixed $country): array
    {
        $country = trim((string) $country);
        $query = DB::table('stj_paises')->select(['pai_id', 'pai_codigo']);

        if ($country === '') {
            throw ValidationException::withMessages([
                'country' => 'Debe seleccionar un pais.',
            ]);
        }

        if (is_numeric($country)) {
            $resolved = $query->where('pai_id', (int) $country)->first();
        } else {
            $resolved = $query->where('pai_codigo', strtoupper($country))->first();
        }

        if (! $resolved) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return [
            'id' => (int) $resolved->pai_id,
            'code' => strtoupper((string) $resolved->pai_codigo),
        ];
    }

    private function storeBanner(UploadedFile $banner, string $name): string
    {
        $optimized = $this->images->optimize($banner);
        $filename = now()->format('YmdHis').'-'.Str::slug($name).'.'.$optimized->extension;

        try {
            if ($this->shouldStoreBannerInSpaces()) {
                $path = 'colecciones/banners/'.$filename;

                Storage::disk('spaces')->put($path, fopen($optimized->path, 'rb'), [
                    'visibility' => 'public',
                    'ContentType' => $optimized->mime,
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ]);

                return rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.$path;
            }

            $directory = public_path('images/colecciones');

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            copy($optimized->path, $directory.DIRECTORY_SEPARATOR.$filename);

            return '/images/colecciones/'.$filename;
        } finally {
            if (is_file($optimized->path)) {
                unlink($optimized->path);
            }
        }
    }

    private function shouldStoreBannerInSpaces(): bool
    {
        return filled(config('filesystems.disks.spaces.key'))
            && filled(config('filesystems.disks.spaces.secret'))
            && filled(config('filesystems.disks.spaces.bucket'))
            && filled(config('filesystems.disks.spaces.endpoint'))
            && filled(config('filesystems.disks.spaces.url'));
    }

    private function withCacheVersion(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'v='.now()->timestamp;
    }

    private function resolveCountryId(?string $country): ?int
    {
        $country = trim((string) $country);

        if ($country === '') {
            return null;
        }

        if (is_numeric($country)) {
            return (int) $country;
        }

        $resolved = DB::table('stj_paises')
            ->where('pai_codigo', strtoupper($country))
            ->value('pai_id');

        return $resolved !== null ? (int) $resolved : null;
    }

    private function normalizeCollection(object $collection): array
    {
        $codes = collect(explode(',', (string) $collection->col_codigos))
            ->map(fn (string $code) => trim($code))
            ->filter()
            ->values();

        return [
            'id' => (int) $collection->col_id,
            'uuid' => (string) $collection->col_uuid,
            'name' => trim((string) $collection->col_nombre),
            'title' => trim((string) $collection->col_titulo),
            'header' => trim((string) $collection->col_header),
            'mobilePosition' => $collection->col_posicion_movil,
            'codesCount' => $codes->count(),
            'codes' => $codes->values()->all(),
            'codesPreview' => $codes->take(5)->values()->all(),
            'country' => [
                'id' => (int) $collection->col_pais,
                'code' => strtoupper((string) $collection->pai_codigo),
                'name' => trim((string) $collection->pai_nombre),
            ],
            'createdAt' => $collection->col_fecha_creado,
        ];
    }
}
