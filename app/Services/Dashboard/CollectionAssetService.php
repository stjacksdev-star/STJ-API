<?php

namespace App\Services\Dashboard;

use App\Services\Media\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollectionAssetService
{
    public function __construct(
        private readonly ImageOptimizer $images,
    ) {
    }

    public function index(int $collectionId): array
    {
        $collection = $this->collection($collectionId);
        $linkPrefix = $this->linkPrefix($collectionId);

        return [
            'collection' => $collection,
            'link' => $this->collectionLink($collection),
            'assets' => DB::table('stj_assets')
                ->where('ast_link', 'like', $linkPrefix.'%')
                ->orderByDesc('ast_id')
                ->get()
                ->map(fn ($asset) => $this->normalizeAsset($asset))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $collectionId, array $data, UploadedFile $image, ?UploadedFile $mobileImage = null): array
    {
        $collection = $this->collection($collectionId);
        $type = strtoupper((string) $data['type']);
        $countryCode = strtolower((string) $collection->pai_codigo);

        $id = DB::table('stj_assets')->insertGetId([
            'ast_pais' => (int) $collection->col_pais,
            'ast_plataforma' => $data['platform'] ?? 'WEB',
            'ast_tipo' => $type,
            'ast_posicion' => $data['position'] ?? null,
            'ast_orden' => $data['order'] ?? 1,
            'ast_estado' => $data['status'] ?? 'PENDIENTE',
            'ast_imagen' => $this->storeImage($image, $type, $countryCode, $collectionId, 'desktop'),
            'ast_imagen_movil' => $mobileImage
                ? $this->storeImage($mobileImage, $type, $countryCode, $collectionId, 'mobile')
                : null,
            'ast_inicio' => $data['startAt'],
            'ast_fin' => $data['endAt'],
            'ast_link' => $this->collectionLink($collection),
            'ast_tipo_accion' => 7,
            'ast_idpromocion' => $collectionId,
            'ast_titulo' => $data['title'] ?? null,
        ]);

        return $this->findAsset($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $assetId, array $data, ?UploadedFile $image = null, ?UploadedFile $mobileImage = null): array
    {
        $asset = DB::table('stj_assets')->where('ast_id', $assetId)->first();

        if (! $asset) {
            throw ValidationException::withMessages([
                'asset' => 'El asset seleccionado no existe.',
            ]);
        }

        $country = DB::table('stj_paises')
            ->select(['pai_codigo'])
            ->where('pai_id', $asset->ast_pais)
            ->first();

        $countryCode = strtolower((string) ($country->pai_codigo ?? 'sv'));
        $type = strtoupper((string) $data['type']);
        $collectionId = $this->collectionIdFromLink((string) $asset->ast_link);

        $updates = [
            'ast_plataforma' => $data['platform'] ?? $asset->ast_plataforma,
            'ast_tipo' => $type,
            'ast_posicion' => $data['position'] ?? null,
            'ast_orden' => $data['order'] ?? $asset->ast_orden,
            'ast_estado' => $data['status'] ?? $asset->ast_estado,
            'ast_inicio' => $data['startAt'],
            'ast_fin' => $data['endAt'],
            'ast_tipo_accion' => 7,
            'ast_idpromocion' => $collectionId,
            'ast_titulo' => $data['title'] ?? null,
        ];

        if ($image) {
            $updates['ast_imagen'] = $this->withCacheVersion(
                $this->storeImage($image, $type, $countryCode, $collectionId, 'desktop'),
            );
        }

        if ($mobileImage) {
            $updates['ast_imagen_movil'] = $this->withCacheVersion(
                $this->storeImage($mobileImage, $type, $countryCode, $collectionId, 'mobile'),
            );
        }

        DB::table('stj_assets')->where('ast_id', $assetId)->update($updates);

        return $this->findAsset($assetId);
    }

    private function findAsset(int $id): array
    {
        $asset = DB::table('stj_assets')->where('ast_id', $id)->first();

        return $this->normalizeAsset($asset);
    }

    private function collection(int $id): object
    {
        $collection = DB::table('stj_coleccion as c')
            ->leftJoin('stj_paises as p', 'p.pai_id', '=', 'c.col_pais')
            ->select([
                'c.col_id',
                'c.col_pais',
                'c.col_nombre',
                'c.col_titulo',
                'p.pai_codigo',
                'p.pai_nombre',
            ])
            ->where('c.col_id', $id)
            ->first();

        if (! $collection) {
            throw ValidationException::withMessages([
                'collection' => 'La coleccion seleccionada no existe.',
            ]);
        }

        return $collection;
    }

    private function collectionLink(object $collection): string
    {
        return $this->linkPrefix((int) $collection->col_id).$this->linkLabel((string) $collection->col_nombre);
    }

    private function linkPrefix(int $collectionId): string
    {
        return "Productos/Colecciones/?id={$collectionId}&";
    }

    private function linkLabel(string $name): string
    {
        return strtoupper(Str::slug($name, ''));
    }

    private function storeImage(UploadedFile $image, string $type, string $countryCode, int $collectionId, string $variant): string
    {
        $optimized = $this->images->optimize($image);
        $folder = $this->folderForType($type);
        $filename = $collectionId.'-'.$variant.'-'.now()->format('YmdHis').'-'.Str::random(6).'.'.$optimized->extension;
        $path = "{$folder}/{$countryCode}/{$filename}";

        try {
            if ($this->shouldStoreInSpaces()) {
                Storage::disk('spaces')->put($path, fopen($optimized->path, 'rb'), [
                    'visibility' => 'public',
                    'ContentType' => $optimized->mime,
                    'CacheControl' => 'public, max-age=31536000, immutable',
                ]);

                return rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.$path;
            }

            $directory = public_path("images/{$folder}/{$countryCode}");

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            copy($optimized->path, $directory.DIRECTORY_SEPARATOR.$filename);

            return "/images/{$folder}/{$countryCode}/{$filename}";
        } finally {
            if (is_file($optimized->path)) {
                unlink($optimized->path);
            }
        }
    }

    private function folderForType(string $type): string
    {
        return match ($type) {
            'LO-MAS-NUEVO' => 'lo-mas-nuevo',
            default => strtolower($type),
        };
    }

    private function shouldStoreInSpaces(): bool
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

    private function collectionIdFromLink(string $link): int
    {
        preg_match('/id=(\d+)/', $link, $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function normalizeAsset(object $asset): array
    {
        return [
            'id' => (int) $asset->ast_id,
            'countryId' => $asset->ast_pais !== null ? (int) $asset->ast_pais : null,
            'platform' => $asset->ast_plataforma,
            'type' => $asset->ast_tipo,
            'position' => $asset->ast_posicion,
            'order' => $asset->ast_orden !== null ? (int) $asset->ast_orden : null,
            'status' => $asset->ast_estado,
            'image' => $asset->ast_imagen,
            'mobileImage' => $asset->ast_imagen_movil,
            'startAt' => $asset->ast_inicio,
            'endAt' => $asset->ast_fin,
            'link' => $asset->ast_link,
            'loadedAt' => $asset->ast_fecha_carga,
            'actionType' => $asset->ast_tipo_accion !== null ? (int) $asset->ast_tipo_accion : null,
            'promotionId' => $asset->ast_idpromocion !== null ? (int) $asset->ast_idpromocion : 0,
            'title' => $asset->ast_titulo,
        ];
    }
}
