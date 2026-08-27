<?php

namespace App\Services\Dashboard;

use App\Services\Media\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StandaloneAssetService
{
    public function __construct(private readonly ImageOptimizer $images) {}

    public function index(): array
    {
        return [
            'countries' => DB::table('stj_paises')->orderBy('pai_id')->get(['pai_id', 'pai_codigo', 'pai_nombre'])
                ->map(fn ($country) => ['id' => (int) $country->pai_id, 'code' => strtoupper((string) $country->pai_codigo), 'name' => trim((string) $country->pai_nombre)])->all(),
            'assets' => DB::table('stj_assets as a')->leftJoin('stj_paises as p', 'p.pai_id', '=', 'a.ast_pais')
                ->where(fn ($query) => $query->whereNull('a.ast_tipo_accion')->orWhere('a.ast_tipo_accion', 0))
                ->where(fn ($query) => $query->whereNull('a.ast_idpromocion')->orWhere('a.ast_idpromocion', 0))
                ->select(['a.*', 'p.pai_codigo', 'p.pai_nombre'])->orderByDesc('a.ast_id')->get()
                ->map(fn ($asset) => $this->normalize($asset))->all(),
        ];
    }

    public function create(array $data, UploadedFile $image, ?UploadedFile $mobileImage): array
    {
        $country = $this->country((int) $data['countryId']);
        $type = strtoupper((string) $data['type']);
        $id = DB::table('stj_assets')->insertGetId($this->values($data) + [
            'ast_imagen' => $this->storeImage($image, $type, strtolower((string) $country->pai_codigo), 'desktop'),
            'ast_imagen_movil' => $mobileImage ? $this->storeImage($mobileImage, $type, strtolower((string) $country->pai_codigo), 'mobile') : null,
        ]);

        return $this->find($id);
    }

    public function update(int $id, array $data, ?UploadedFile $image, ?UploadedFile $mobileImage): array
    {
        $asset = DB::table('stj_assets')->where('ast_id', $id)->where(fn ($q) => $q->whereNull('ast_tipo_accion')->orWhere('ast_tipo_accion', 0))
            ->where(fn ($q) => $q->whereNull('ast_idpromocion')->orWhere('ast_idpromocion', 0))->first();
        if (! $asset) {
            throw ValidationException::withMessages(['asset' => 'El asset independiente seleccionado no existe.']);
        }
        $country = $this->country((int) $data['countryId']);
        $type = strtoupper((string) $data['type']);
        $values = $this->values($data);
        if ($image) $values['ast_imagen'] = $this->storeImage($image, $type, strtolower((string) $country->pai_codigo), 'desktop');
        if ($mobileImage) $values['ast_imagen_movil'] = $this->storeImage($mobileImage, $type, strtolower((string) $country->pai_codigo), 'mobile');
        DB::table('stj_assets')->where('ast_id', $id)->update($values);

        return $this->find($id);
    }

    private function values(array $data): array
    {
        return [
            'ast_pais' => (int) $data['countryId'], 'ast_plataforma' => $data['platform'] ?? 'WEB',
            'ast_tipo' => strtoupper((string) $data['type']), 'ast_posicion' => $data['position'] ?? null,
            'ast_orden' => $data['order'] ?? 1, 'ast_estado' => $data['status'] ?? 'PENDIENTE',
            'ast_inicio' => $data['startAt'], 'ast_fin' => $data['endAt'], 'ast_link' => $data['link'] ?? null,
            'ast_titulo' => $data['title'] ?? null, 'ast_tipo_accion' => 0, 'ast_idpromocion' => 0,
        ];
    }

    private function country(int $id): object
    {
        $country = DB::table('stj_paises')->where('pai_id', $id)->first(['pai_id', 'pai_codigo']);
        if (! $country) throw ValidationException::withMessages(['countryId' => 'El pais seleccionado no existe.']);
        return $country;
    }

    private function find(int $id): array
    {
        $asset = DB::table('stj_assets as a')->leftJoin('stj_paises as p', 'p.pai_id', '=', 'a.ast_pais')->where('a.ast_id', $id)
            ->select(['a.*', 'p.pai_codigo', 'p.pai_nombre'])->first();
        return $this->normalize($asset);
    }

    private function storeImage(UploadedFile $image, string $type, string $country, string $variant): string
    {
        $optimized = $this->images->optimize($image);
        $folder = 'assets/'.strtolower($type).'/'.$country;
        $filename = $variant.'-'.now()->format('YmdHis').'-'.Str::random(8).'.'.$optimized->extension;
        $path = $folder.'/'.$filename;
        try {
            if (filled(config('filesystems.disks.spaces.key'))
                && filled(config('filesystems.disks.spaces.secret'))
                && filled(config('filesystems.disks.spaces.bucket'))
                && filled(config('filesystems.disks.spaces.endpoint'))
                && filled(config('filesystems.disks.spaces.url'))) {
                Storage::disk('spaces')->put($path, fopen($optimized->path, 'rb'), ['visibility' => 'public', 'ContentType' => $optimized->mime]);
                return rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.$path;
            }
            $directory = public_path('images/'.$folder);
            if (! is_dir($directory)) mkdir($directory, 0755, true);
            copy($optimized->path, $directory.DIRECTORY_SEPARATOR.$filename);
            return '/images/'.$path;
        } finally {
            if (is_file($optimized->path)) unlink($optimized->path);
        }
    }

    private function normalize(object $asset): array
    {
        return [
            'id' => (int) $asset->ast_id,
            'country' => ['id' => (int) $asset->ast_pais, 'code' => strtoupper((string) $asset->pai_codigo), 'name' => trim((string) $asset->pai_nombre)],
            'platform' => $asset->ast_plataforma, 'type' => $asset->ast_tipo, 'position' => $asset->ast_posicion,
            'order' => $asset->ast_orden !== null ? (int) $asset->ast_orden : null, 'status' => $asset->ast_estado,
            'image' => $asset->ast_imagen, 'mobileImage' => $asset->ast_imagen_movil, 'startAt' => $asset->ast_inicio,
            'endAt' => $asset->ast_fin, 'link' => $asset->ast_link, 'title' => $asset->ast_titulo,
        ];
    }
}
