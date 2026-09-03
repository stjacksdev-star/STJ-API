<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCategoryService
{
    public function index(int $limit = 500): array
    {
        $categories = DB::table('stj_categorias')
            ->select([
                'cat_id',
                'cat_orden',
                'cat_orden_app',
                'cat_codigo',
                'cat_nombre',
                'cat_align',
                'cat_header',
                'cat_logo',
                'cat_nombre_app',
                'cat_logo_app',
                'cat_si_sub_otras',
                'cat_sub_otras',
                'cat_descripcion',
                'cat_tallas',
                'cat_marca',
                'cat_habilitado_sv',
                'cat_habilitado_gt',
                'cat_habilitado_cr',
                'cat_habilitado_ni',
                'cat_habilitado_app',
                'cat_a_usuario',
                'cat_a_ip',
                'cat_a_fecha',
                'cat_a_version',
            ])
            ->orderBy('cat_orden')
            ->orderBy('cat_nombre')
            ->limit(max(1, min($limit, 1000)))
            ->get()
            ->map(fn (object $category) => $this->normalize($category))
            ->values()
            ->all();

        return [
            'options' => [
                'alignments' => ['left', 'center', 'right'],
                'brands' => ['ST JACKS', 'BUNGEE', 'BASICS', 'BASIKOS', 'JACK & CO'],
            ],
            'categories' => $categories,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): array
    {
        $id = DB::table('stj_categorias')->insertGetId($this->payload($data));

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): array
    {
        $existing = DB::table('stj_categorias')->where('cat_id', $id)->first();

        if (! $existing) {
            throw ValidationException::withMessages([
                'category' => 'La categoria seleccionada no existe.',
            ]);
        }

        DB::table('stj_categorias')
            ->where('cat_id', $id)
            ->update($this->payload($data, $existing));

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $existing = DB::table('stj_categorias')->where('cat_id', $id)->first();

        if (! $existing) {
            throw ValidationException::withMessages([
                'category' => 'La categoria seleccionada no existe.',
            ]);
        }

        $products = DB::table('stj_productos')->where('pro_categoria', $id)->count();
        $subcategories = DB::table('stj_sub_categorias')->where('sca_categoria', $id)->count();

        if ($products > 0 || $subcategories > 0) {
            throw ValidationException::withMessages([
                'category' => "No se puede eliminar: tiene {$products} productos y {$subcategories} subcategorias relacionadas.",
            ]);
        }

        DB::table('stj_categorias')->where('cat_id', $id)->delete();
    }

    private function find(int $id): array
    {
        $category = DB::table('stj_categorias')->where('cat_id', $id)->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category' => 'La categoria seleccionada no existe.',
            ]);
        }

        return $this->normalize($category);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data, ?object $existing = null): array
    {
        $actor = is_array($data['actor'] ?? null) ? $data['actor'] : [];

        return [
            'cat_orden' => $this->nullableInt($data['order'] ?? null),
            'cat_orden_app' => $this->nullableInt($data['appOrder'] ?? null),
            'cat_codigo' => trim((string) $data['code']),
            'cat_nombre' => trim((string) $data['name']),
            'cat_align' => $this->nullableString($data['align'] ?? null),
            'cat_header' => $this->nullableString($data['header'] ?? null),
            'cat_logo' => trim((string) $data['logo']),
            'cat_nombre_app' => $this->nullableString($data['appName'] ?? null),
            'cat_logo_app' => trim((string) $data['appLogo']),
            'cat_si_sub_otras' => $this->boolInt($data['hasOtherSubcategories'] ?? false),
            'cat_sub_otras' => $this->nullableString($data['otherSubcategories'] ?? null),
            'cat_descripcion' => trim((string) $data['description']),
            'cat_tallas' => trim((string) $data['sizes']),
            'cat_marca' => $this->nullableString($data['brand'] ?? null),
            'cat_habilitado_sv' => $this->boolInt($data['enabledSv'] ?? false),
            'cat_habilitado_gt' => $this->boolInt($data['enabledGt'] ?? false),
            'cat_habilitado_cr' => $this->boolInt($data['enabledCr'] ?? false),
            'cat_habilitado_ni' => $this->boolInt($data['enabledNi'] ?? false),
            'cat_habilitado_app' => $this->boolInt($data['enabledApp'] ?? false),
            'cat_a_usuario' => substr((string) ($actor['email'] ?? $actor['username'] ?? $actor['name'] ?? 'dashboard'), 0, 100),
            'cat_a_ip' => substr((string) ($actor['ip'] ?? ''), 0, 20),
            'cat_a_generales' => 'dashboard categorias crud',
            'cat_a_fecha' => now(),
            'cat_a_version' => ((int) ($existing->cat_a_version ?? 0)) + 1,
        ];
    }

    private function normalize(object $category): array
    {
        return [
            'id' => (int) $category->cat_id,
            'order' => $category->cat_orden !== null ? (int) $category->cat_orden : null,
            'appOrder' => $category->cat_orden_app !== null ? (int) $category->cat_orden_app : null,
            'code' => (string) $category->cat_codigo,
            'name' => (string) $category->cat_nombre,
            'align' => $category->cat_align,
            'header' => $category->cat_header,
            'logo' => (string) $category->cat_logo,
            'appName' => $category->cat_nombre_app,
            'appLogo' => (string) $category->cat_logo_app,
            'hasOtherSubcategories' => (bool) $category->cat_si_sub_otras,
            'otherSubcategories' => $category->cat_sub_otras,
            'description' => (string) $category->cat_descripcion,
            'sizes' => (string) $category->cat_tallas,
            'brand' => $category->cat_marca,
            'enabled' => [
                'sv' => (bool) $category->cat_habilitado_sv,
                'gt' => (bool) $category->cat_habilitado_gt,
                'cr' => (bool) $category->cat_habilitado_cr,
                'ni' => (bool) $category->cat_habilitado_ni,
                'app' => (bool) $category->cat_habilitado_app,
            ],
            'audit' => [
                'user' => $category->cat_a_usuario ?? null,
                'ip' => $category->cat_a_ip ?? null,
                'updatedAt' => $category->cat_a_fecha ?? null,
                'version' => $category->cat_a_version !== null ? (int) $category->cat_a_version : null,
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function boolInt(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}
