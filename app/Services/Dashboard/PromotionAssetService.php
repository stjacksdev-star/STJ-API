<?php

namespace App\Services\Dashboard;

use App\Services\Media\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PromotionAssetService
{
    public function __construct(
        private readonly ImageOptimizer $images,
    ) {
    }

    public function index(int $promotionId): array
    {
        $promotion = $this->promotion($promotionId);

        return [
            'promotion' => $this->normalizePromotion($promotion),
            'link' => $this->promotionLink($promotion),
            'assets' => DB::table('stj_assets')
                ->where('ast_tipo_accion', 1)
                ->where('ast_idpromocion', $promotionId)
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
    public function create(int $promotionId, array $data, UploadedFile $image, ?UploadedFile $mobileImage = null): array
    {
        $promotion = $this->promotion($promotionId);
        $this->ensurePromotionEditable($promotion);

        $type = strtoupper((string) $data['type']);
        $countryCode = strtolower((string) $promotion->pai_codigo);
        $this->ensureUniqueType($promotionId, $type);

        $id = DB::table('stj_assets')->insertGetId([
            'ast_pais' => (int) $promotion->prm_pais,
            'ast_plataforma' => $data['platform'] ?? 'WEB',
            'ast_tipo' => $type,
            'ast_posicion' => $data['position'] ?? null,
            'ast_orden' => $data['order'] ?? 1,
            'ast_estado' => $data['status'] ?? 'PENDIENTE',
            'ast_imagen' => $this->storeImage($image, $type, $countryCode, $promotionId, 'desktop'),
            'ast_imagen_movil' => $mobileImage
                ? $this->storeImage($mobileImage, $type, $countryCode, $promotionId, 'mobile')
                : null,
            'ast_inicio' => $data['startAt'],
            'ast_fin' => $data['endAt'],
            'ast_link' => $this->promotionLink($promotion),
            'ast_tipo_accion' => 1,
            'ast_idpromocion' => $promotionId,
            'ast_titulo' => $data['title'] ?? null,
        ]);

        return $this->findAsset($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $assetId, array $data, ?UploadedFile $image = null, ?UploadedFile $mobileImage = null): array
    {
        $asset = $this->promotionAsset($assetId);
        $promotionId = (int) $asset->ast_idpromocion;
        $promotion = $this->promotion($promotionId);
        $this->ensurePromotionEditable($promotion);

        $type = strtoupper((string) $data['type']);
        $countryCode = strtolower((string) $promotion->pai_codigo);
        $this->ensureUniqueType($promotionId, $type, $assetId);

        $updates = [
            'ast_plataforma' => $data['platform'] ?? $asset->ast_plataforma,
            'ast_tipo' => $type,
            'ast_posicion' => $data['position'] ?? null,
            'ast_orden' => $data['order'] ?? $asset->ast_orden,
            'ast_estado' => $data['status'] ?? $asset->ast_estado,
            'ast_inicio' => $data['startAt'],
            'ast_fin' => $data['endAt'],
            'ast_link' => $this->promotionLink($promotion),
            'ast_tipo_accion' => 1,
            'ast_idpromocion' => $promotionId,
            'ast_titulo' => $data['title'] ?? null,
        ];

        if ($image) {
            $updates['ast_imagen'] = $this->withCacheVersion(
                $this->storeImage($image, $type, $countryCode, $promotionId, 'desktop'),
            );
        }

        if ($mobileImage) {
            $updates['ast_imagen_movil'] = $this->withCacheVersion(
                $this->storeImage($mobileImage, $type, $countryCode, $promotionId, 'mobile'),
            );
        }

        DB::table('stj_assets')->where('ast_id', $assetId)->update($updates);

        return $this->findAsset($assetId);
    }

    public function delete(int $assetId): void
    {
        $asset = $this->promotionAsset($assetId);
        $promotion = $this->promotion((int) $asset->ast_idpromocion);
        $this->ensurePromotionEditable($promotion);

        DB::table('stj_assets')->where('ast_id', $assetId)->delete();
    }

    public function updateHeader(int $promotionId, UploadedFile $header): array
    {
        $promotion = $this->promotion($promotionId);
        $this->ensurePromotionEditable($promotion);

        $countryCode = strtolower((string) $promotion->pai_codigo);
        $path = $this->storeImage($header, 'BANNER', $countryCode, $promotionId, 'header');

        DB::table('stj_promociones')
            ->where('prm_id', $promotionId)
            ->update([
                'prm_encabezado' => $this->withCacheVersion($path),
            ]);

        return $this->normalizePromotion($this->promotion($promotionId));
    }

    private function findAsset(int $id): array
    {
        $asset = DB::table('stj_assets')->where('ast_id', $id)->first();

        return $this->normalizeAsset($asset);
    }

    private function promotionAsset(int $assetId): object
    {
        $asset = DB::table('stj_assets')
            ->where('ast_id', $assetId)
            ->where('ast_tipo_accion', 1)
            ->first();

        if (! $asset) {
            throw ValidationException::withMessages([
                'asset' => 'El asset de promocion seleccionado no existe.',
            ]);
        }

        return $asset;
    }

    private function promotion(int $id): object
    {
        $promotion = DB::table('stj_promociones as p')
            ->leftJoin('stj_paises as c', 'c.pai_id', '=', 'p.prm_pais')
            ->select([
                'p.prm_id',
                'p.prm_pais',
                'p.prm_nombre',
                'p.prm_nombre_comercial',
                'p.prm_estado',
                'p.prm_encabezado',
                'c.pai_codigo',
                'c.pai_nombre',
            ])
            ->where('p.prm_id', $id)
            ->first();

        if (! $promotion) {
            throw ValidationException::withMessages([
                'promotion' => 'La promocion seleccionada no existe.',
            ]);
        }

        return $promotion;
    }

    private function normalizePromotion(object $promotion): array
    {
        return [
            'id' => (int) $promotion->prm_id,
            'name' => trim((string) $promotion->prm_nombre),
            'commercialName' => trim((string) $promotion->prm_nombre_comercial),
            'status' => $promotion->prm_estado,
            'header' => trim((string) $promotion->prm_encabezado),
            'country' => [
                'id' => $promotion->prm_pais !== null ? (int) $promotion->prm_pais : null,
                'code' => strtoupper((string) $promotion->pai_codigo),
                'name' => trim((string) $promotion->pai_nombre),
            ],
        ];
    }

    private function ensurePromotionEditable(object $promotion): void
    {
        if (in_array((string) $promotion->prm_estado, ['PENDIENTE', 'EN-PROCESO'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'promotion' => 'Solo se pueden modificar assets y banner de promociones pendientes o en proceso.',
        ]);
    }

    private function ensureUniqueType(int $promotionId, string $type, ?int $ignoreAssetId = null): void
    {
        $exists = DB::table('stj_assets')
            ->where('ast_tipo_accion', 1)
            ->where('ast_idpromocion', $promotionId)
            ->where('ast_tipo', $type)
            ->when($ignoreAssetId !== null, fn ($query) => $query->where('ast_id', '!=', $ignoreAssetId))
            ->exists();

        if (! $exists) {
            return;
        }

        throw ValidationException::withMessages([
            'type' => "La promocion ya tiene un asset de tipo {$type}.",
        ]);
    }

    private function promotionLink(object $promotion): string
    {
        return "Promociones/?idPromocion={$promotion->prm_id}&Promo";
    }

    private function storeImage(UploadedFile $image, string $type, string $countryCode, int $promotionId, string $variant): string
    {
        $optimized = $this->images->optimize($image);
        $folder = $this->folderForType($type);
        $filename = $promotionId.'-'.$variant.'-'.now()->format('YmdHis').'-'.Str::random(6).'.'.$optimized->extension;
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
