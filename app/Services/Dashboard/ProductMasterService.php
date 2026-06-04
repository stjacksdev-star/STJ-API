<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class ProductMasterService
{
    private const MAX_PHOTO_IMPORT_ROWS = 30;
    private const PHOTO_IMPORT_TIMEOUT_SECONDS = 900;
    private const PHOTO_DOWNLOAD_TIMEOUT_SECONDS = 90;

    private const PRODUCT_COLUMNS = [
        'A' => 'codigo',
        'B' => 'nombre',
        'C' => 'descripcion',
        'D' => 'marca',
        'E' => 'tags',
        'F' => 'tallas',
        'G' => 'personaje',
        'H' => 'categoria',
        'I' => 'subcategoria',
        'J' => 'coleccion',
        'K' => 'oracleAnio',
        'L' => 'oracleTrimestre',
        'M' => 'oracleColeccion',
        'N' => 'oracleGenero',
        'O' => 'oracleMarca',
        'P' => 'oracleCategoria',
        'Q' => 'oracleLicencia',
        'R' => 'oraclePersonaje',
    ];

    private const PHOTO_COLUMNS = [
        'A' => 'codigo',
        'B' => 'orden',
        'C' => 'url',
    ];

    private const PHOTO_VARIANTS = [
        'p100' => 100,
        'p200' => 200,
        'p400' => 400,
        'productos_thums' => 450,
    ];

    /**
     * @return array<string, mixed>
     */
    public function index(?string $search = null, int $limit = 300): array
    {
        $search = trim((string) $search);

        $products = DB::table('stj_productos as p')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as s', 's.sca_id', '=', 'p.pro_sub_categoria')
            ->select([
                'p.pro_id',
                'p.pro_codigo',
                'p.pro_nombre',
                'p.pro_marca',
                'p.pro_tallas',
                'p.pro_coleccion',
                'p.pro_personaje',
                'p.pro_estatus',
                'p.pro_registro',
                'p.pro_thumbs',
                'c.cat_id',
                'c.cat_nombre',
                's.sca_id',
                's.sca_nombre',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($builder) use ($search) {
                    $builder
                        ->where('p.pro_codigo', 'like', "%{$search}%")
                        ->orWhere('p.pro_nombre', 'like', "%{$search}%")
                        ->orWhere('p.pro_tags', 'like', "%{$search}%")
                        ->orWhere('p.pro_coleccion', 'like', "%{$search}%")
                        ->orWhere('p.pro_personaje', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('p.pro_id')
            ->limit(max(1, min($limit, 1000)))
            ->get()
            ->map(fn (object $product) => $this->normalizeProduct($product))
            ->values()
            ->all();

        return [
            'filters' => [
                'search' => $search,
                'limit' => $limit,
            ],
            'products' => $products,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();

        $summary = [
            'rows' => max(0, $highestRow - 1),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'photosIgnored' => $spreadsheet->getSheetCount() > 1,
        ];
        $log = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $data = $this->readProductRow($sheet, $row);

                if ($data['codigo'] === '') {
                    $summary['skipped']++;
                    continue;
                }

                if (! $this->isValidBrand($data['marca'])) {
                    $summary['failed']++;
                    $log[] = $this->rowLog(
                        $row,
                        $data['codigo'],
                        'error',
                        "Marca no permitida: {$data['marca']}. Valores validos: ST JACKS, BUNGEE, BASICS, FITONE, JACK & CO.",
                    );
                    continue;
                }

                $category = $this->findCategory($data['categoria']);

                if (! $category) {
                    $summary['failed']++;
                    $log[] = $this->rowLog($row, $data['codigo'], 'error', "No existe la categoria {$data['categoria']}.");
                    continue;
                }

                $subcategory = $this->findSubcategory((int) $category->cat_id, $data['subcategoria']);

                if (! $subcategory) {
                    $summary['failed']++;
                    $log[] = $this->rowLog($row, $data['codigo'], 'error', "No existe la subcategoria {$data['subcategoria']} para {$data['categoria']}.");
                    continue;
                }

                $payload = $this->productPayload($data, (int) $category->cat_id, (int) $subcategory->sca_id);
                $existing = DB::table('stj_productos')->where('pro_codigo', $data['codigo'])->first();

                DB::transaction(function () use ($existing, $payload, &$summary): void {
                    if ($existing) {
                        DB::table('stj_productos')
                            ->where('pro_id', $existing->pro_id)
                            ->update($payload);
                        $summary['updated']++;
                    } else {
                        DB::table('stj_productos')->insert([
                            ...$payload,
                            'pro_registro' => now(),
                        ]);
                        $summary['created']++;
                    }
                });

                $log[] = $this->rowLog(
                    $row,
                    $data['codigo'],
                    $existing ? 'updated' : 'created',
                    $existing ? 'Producto actualizado.' : 'Producto creado.',
                );
            } catch (Throwable $exception) {
                $summary['failed']++;
                $log[] = $this->rowLog(
                    $row,
                    $this->cell($sheet, 'A', $row),
                    'error',
                    'Error al procesar la fila.',
                );
            }
        }

        $spreadsheet->disconnectWorksheets();

        return [
            'summary' => $summary,
            'log' => array_slice($log, 0, 500),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $id): array
    {
        $product = DB::table('stj_productos as p')
            ->leftJoin('stj_categorias as c', 'c.cat_id', '=', 'p.pro_categoria')
            ->leftJoin('stj_sub_categorias as s', 's.sca_id', '=', 'p.pro_sub_categoria')
            ->select([
                'p.*',
                'c.cat_nombre',
                'c.cat_codigo',
                's.sca_nombre',
                's.sca_codigo',
            ])
            ->where('p.pro_id', $id)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product' => 'El producto seleccionado no existe.',
            ]);
        }

        return [
            'id' => (int) $product->pro_id,
            'code' => (string) $product->pro_codigo,
            'fields' => [
                'pro_id' => $product->pro_id,
                'pro_codigo' => $product->pro_codigo,
                'pro_thumbs' => $product->pro_thumbs,
                'pro_nombre' => $product->pro_nombre,
                'pro_descripcion' => $product->pro_descripcion,
                'pro_categoria' => $product->pro_categoria,
                'cat_codigo' => $product->cat_codigo,
                'cat_nombre' => $product->cat_nombre,
                'pro_sub_categoria' => $product->pro_sub_categoria,
                'sca_codigo' => $product->sca_codigo,
                'sca_nombre' => $product->sca_nombre,
                'pro_coleccion' => $product->pro_coleccion,
                'pro_tiene_talla' => $product->pro_tiene_talla,
                'pro_tallas' => $product->pro_tallas,
                'pro_personaje' => $product->pro_personaje,
                'pro_tags' => $product->pro_tags,
                'pro_registro' => $product->pro_registro,
                'pro_estatus' => $product->pro_estatus,
                'pro_marca' => $product->pro_marca,
                'pro_oc_categoria' => $product->pro_oc_categoria,
                'pro_oc_coleccion' => $product->pro_oc_coleccion,
                'pro_oc_genero' => $product->pro_oc_genero,
                'pro_oc_licencia' => $product->pro_oc_licencia,
                'pro_oc_marca' => $product->pro_oc_marca,
                'pro_oc_personaje' => $product->pro_oc_personaje,
                'pro_oc_anio' => $product->pro_oc_anio,
                'pro_oc_trimestre' => $product->pro_oc_trimestre,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function photos(int $id): array
    {
        $product = DB::table('stj_productos')
            ->select(['pro_id', 'pro_codigo', 'pro_nombre', 'pro_thumbs'])
            ->where('pro_id', $id)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product' => 'El producto seleccionado no existe.',
            ]);
        }

        $photos = DB::table('stj_productos_fotos')
            ->where('pfo_producto', $id)
            ->orderByDesc('pfo_portada')
            ->orderBy('pfo_orden')
            ->get()
            ->map(fn (object $photo) => $this->normalizePhoto($photo))
            ->values()
            ->all();

        return [
            'product' => [
                'id' => (int) $product->pro_id,
                'code' => (string) $product->pro_codigo,
                'name' => (string) $product->pro_nombre,
                'thumb' => $product->pro_thumbs,
            ],
            'photos' => $photos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function countries(int $id): array
    {
        $product = DB::table('stj_productos')
            ->select(['pro_id', 'pro_codigo', 'pro_nombre'])
            ->where('pro_id', $id)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product' => 'El producto seleccionado no existe.',
            ]);
        }

        $countries = DB::table('stj_producto_pais as pp')
            ->join('stj_paises as country', 'country.pai_id', '=', 'pp.ppa_pais')
            ->where('pp.ppa_producto', $id)
            ->orderBy('country.pai_id')
            ->select([
                'pp.ppa_id',
                'pp.ppa_estado',
                'pp.ppa_envio_gratis',
                'pp.ppa_fecha_activo',
                'pp.ppa_fecha_inactivo',
                'pp.ppa_leyenda',
                'pp.ppa_precio_talla',
                'pp.ppa_precio',
                'pp.ppa_precio_tienda',
                'pp.ppa_precio_domicilio',
                'pp.ppa_descuento',
                'pp.ppa_promo_nombre',
                'pp.ppa_es_popular',
                'country.pai_id',
                'country.pai_codigo',
                'country.pai_nombre',
            ])
            ->get()
            ->map(fn (object $row) => [
                'id' => (int) $row->ppa_id,
                'country' => [
                    'id' => (int) $row->pai_id,
                    'code' => (string) $row->pai_codigo,
                    'name' => (string) $row->pai_nombre,
                ],
                'status' => (string) $row->ppa_estado,
                'freeShipping' => (string) $row->ppa_envio_gratis,
                'activeAt' => $row->ppa_fecha_activo,
                'inactiveAt' => $row->ppa_fecha_inactivo,
                'legend' => $row->ppa_leyenda,
                'priceBySize' => $row->ppa_precio_talla,
                'price' => $row->ppa_precio !== null ? (float) $row->ppa_precio : null,
                'storePrice' => $row->ppa_precio_tienda !== null ? (float) $row->ppa_precio_tienda : null,
                'deliveryPrice' => $row->ppa_precio_domicilio !== null ? (float) $row->ppa_precio_domicilio : null,
                'discount' => $row->ppa_descuento !== null ? (float) $row->ppa_descuento : null,
                'promoName' => $row->ppa_promo_nombre,
                'isPopular' => (bool) $row->ppa_es_popular,
            ])
            ->values()
            ->all();

        return [
            'product' => [
                'id' => (int) $product->pro_id,
                'code' => (string) $product->pro_codigo,
                'name' => (string) $product->pro_nombre,
            ],
            'countries' => $countries,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function importPhotos(UploadedFile $file): array
    {
        @set_time_limit(self::PHOTO_IMPORT_TIMEOUT_SECONDS);
        $startedAt = microtime(true);
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $processableRows = min(max(0, $highestRow - 1), self::MAX_PHOTO_IMPORT_ROWS);
        $lastProcessableRow = $processableRows + 1;
        $omittedByLimit = max(0, ($highestRow - 1) - self::MAX_PHOTO_IMPORT_ROWS);

        $summary = [
            'rows' => max(0, $highestRow - 1),
            'processedRows' => $processableRows,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'omittedByLimit' => $omittedByLimit,
            'limit' => self::MAX_PHOTO_IMPORT_ROWS,
            'spaces' => 0,
            'local' => 0,
        ];
        $log = [];

        for ($row = 2; $row <= $lastProcessableRow; $row++) {
            try {
                $data = $this->readPhotoRow($sheet, $row);

                if ($data['codigo'] === '' && $data['url'] === '') {
                    $summary['skipped']++;
                    continue;
                }

                if ($data['codigo'] === '' || $data['orden'] < 1 || $data['url'] === '') {
                    $summary['failed']++;
                    $log[] = $this->rowLog($row, $data['codigo'], 'error', 'Debe indicar codigo, orden y url.');
                    continue;
                }

                $product = DB::table('stj_productos')
                    ->select(['pro_id', 'pro_codigo'])
                    ->where('pro_codigo', $data['codigo'])
                    ->first();

                if (! $product) {
                    $summary['failed']++;
                    $log[] = $this->rowLog($row, $data['codigo'], 'error', 'No existe el producto.');
                    continue;
                }

                $filename = $data['orden'] === 1
                    ? $data['codigo'].'.jpg'
                    : $data['codigo'].'-'.$data['orden'].'.jpg';
                $stored = $this->storeProductPhoto($data['url'], $filename);
                $existing = DB::table('stj_productos_fotos')
                    ->where('pfo_producto', $product->pro_id)
                    ->where('pfo_orden', $data['orden'])
                    ->first();

                DB::transaction(function () use ($existing, $product, $data, $filename, &$summary): void {
                    if ($existing) {
                        DB::table('stj_productos_fotos')
                            ->where('pfo_id', $existing->pfo_id)
                            ->update([
                                'pfo_url' => $filename,
                                'pfo_producto' => (int) $product->pro_id,
                                'pfo_orden' => $data['orden'],
                                'pfo_portada' => $data['orden'] === 1 ? 1 : ((int) ($existing->pfo_portada ?? 0)),
                            ]);
                        $summary['updated']++;
                    } else {
                        DB::table('stj_productos_fotos')->insert([
                            'pfo_url' => $filename,
                            'pfo_producto' => (int) $product->pro_id,
                            'pfo_orden' => $data['orden'],
                            'pfo_portada' => $data['orden'] === 1 ? 1 : 0,
                            'pfo_fecha_registro' => now(),
                        ]);
                        $summary['created']++;
                    }

                    if ($data['orden'] === 1) {
                        DB::table('stj_productos')
                            ->where('pro_id', $product->pro_id)
                            ->update(['pro_thumbs' => $filename]);
                    } else {
                        DB::table('stj_productos')
                            ->where('pro_id', $product->pro_id)
                            ->whereNull('pro_thumbs')
                            ->update(['pro_thumbs' => $filename]);
                    }
                });

                $summary[$stored['storage']]++;
                $log[] = $this->rowLog(
                    $row,
                    $data['codigo'],
                    $existing ? 'updated' : 'created',
                    ($existing ? 'Fotografia actualizada' : 'Fotografia creada')." ({$stored['storageLabel']}).",
                );
            } catch (Throwable $exception) {
                $summary['failed']++;
                $log[] = $this->rowLog(
                    $row,
                    $this->cell($sheet, 'A', $row),
                    'error',
                    'Error al procesar la fotografia: '.$exception->getMessage(),
                );
            }
        }

        $spreadsheet->disconnectWorksheets();
        $duration = round(microtime(true) - $startedAt, 2);
        $summary['durationSeconds'] = $duration;
        $summary['durationLabel'] = $duration >= 60
            ? floor($duration / 60).'m '.round(fmod($duration, 60)).'s'
            : $duration.'s';

        return [
            'summary' => $summary,
            'log' => array_slice($log, 0, 500),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function readProductRow(Worksheet $sheet, int $row): array
    {
        $data = [];

        foreach (self::PRODUCT_COLUMNS as $column => $key) {
            $data[$key] = $this->cell($sheet, $column, $row);
        }

        $data['codigo'] = $this->normalizeCode($data['codigo']);
        $data['marca'] = strtoupper(trim($data['marca']));

        return $data;
    }

    /**
     * @return array{codigo: string, orden: int, url: string}
     */
    private function readPhotoRow(Worksheet $sheet, int $row): array
    {
        $data = [];

        foreach (self::PHOTO_COLUMNS as $column => $key) {
            $data[$key] = $this->cell($sheet, $column, $row);
        }

        return [
            'codigo' => $this->normalizeCode($data['codigo'] ?? ''),
            'orden' => max(0, (int) ($data['orden'] ?? 0)),
            'url' => $this->normalizeDropboxUrl($data['url'] ?? ''),
        ];
    }

    /**
     * @param array<string, string> $data
     * @return array<string, mixed>
     */
    private function productPayload(array $data, int $categoryId, int $subcategoryId): array
    {
        return [
            'pro_codigo' => $data['codigo'],
            'pro_nombre' => mb_substr($data['nombre'], 0, 100),
            'pro_descripcion' => $data['descripcion'] !== '' ? mb_substr($data['descripcion'], 0, 5000) : null,
            'pro_marca' => $data['marca'] !== '' ? $data['marca'] : null,
            'pro_tags' => $data['tags'] !== '' ? mb_substr($data['tags'], 0, 500) : null,
            'pro_categoria' => $categoryId,
            'pro_sub_categoria' => $subcategoryId,
            'pro_coleccion' => $data['coleccion'] !== '' ? mb_substr($data['coleccion'], 0, 100) : null,
            'pro_tiene_talla' => strtoupper($data['tallas']) !== 'TU',
            'pro_tallas' => mb_substr($data['tallas'], 0, 100),
            'pro_personaje' => $data['personaje'] !== '' ? mb_substr($data['personaje'], 0, 100) : null,
            'pro_oc_categoria' => $data['oracleCategoria'] !== '' ? mb_substr($data['oracleCategoria'], 0, 100) : null,
            'pro_oc_coleccion' => $data['oracleColeccion'] !== '' ? mb_substr($data['oracleColeccion'], 0, 100) : null,
            'pro_oc_genero' => $data['oracleGenero'] !== '' ? mb_substr($data['oracleGenero'], 0, 100) : null,
            'pro_oc_licencia' => $data['oracleLicencia'] !== '' ? mb_substr($data['oracleLicencia'], 0, 100) : null,
            'pro_oc_marca' => $data['oracleMarca'] !== '' ? mb_substr($data['oracleMarca'], 0, 100) : null,
            'pro_oc_personaje' => $data['oraclePersonaje'] !== '' ? mb_substr($data['oraclePersonaje'], 0, 100) : null,
            'pro_oc_anio' => $data['oracleAnio'] !== '' ? (int) $data['oracleAnio'] : null,
            'pro_oc_trimestre' => $data['oracleTrimestre'] !== '' ? (int) $data['oracleTrimestre'] : null,
        ];
    }

    private function findCategory(string $name): ?object
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return DB::table('stj_categorias')
            ->select(['cat_id', 'cat_nombre'])
            ->where('cat_nombre', $name)
            ->first()
            ?: DB::table('stj_categorias')
                ->select(['cat_id', 'cat_nombre'])
                ->whereRaw('LOWER(cat_nombre) = ?', [mb_strtolower($name)])
                ->first();
    }

    private function findSubcategory(int $categoryId, string $name): ?object
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return DB::table('stj_sub_categorias')
            ->select(['sca_id', 'sca_nombre'])
            ->where('sca_categoria', $categoryId)
            ->where('sca_nombre', $name)
            ->first()
            ?: DB::table('stj_sub_categorias')
                ->select(['sca_id', 'sca_nombre'])
                ->where('sca_categoria', $categoryId)
                ->whereRaw('LOWER(sca_nombre) = ?', [mb_strtolower($name)])
                ->first();
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        $cell = $sheet->getCell("{$column}{$row}");
        $value = $cell instanceof Cell ? $cell->getCalculatedValue() : null;

        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }

        return trim((string) $value);
    }

    private function normalizeCode(string $value): string
    {
        $value = preg_replace('/[^0-9A-Za-z]/', '', $value) ?: '';

        if ($value !== '' && ctype_digit($value)) {
            return str_pad($value, 10, '0', STR_PAD_LEFT);
        }

        return mb_substr($value, 0, 25);
    }

    private function isValidBrand(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $brands = ['ST JACKS', 'BUNGEE', 'BASICS', 'FITONE', 'JACK & CO'];

        return in_array($value, $brands, true);
    }

    /**
     * @return array{storage: string, storageLabel: string}
     */
    private function storeProductPhoto(string $url, string $filename): array
    {
        $response = Http::timeout(self::PHOTO_DOWNLOAD_TIMEOUT_SECONDS)->get($url);

        if (! $response->successful() || $response->body() === '') {
            throw new \RuntimeException('No se pudo descargar la imagen.');
        }

        $contents = $response->body();
        $mime = $this->imageMime($contents) ?: ($response->header('Content-Type') ?: 'image/jpeg');
        $variants = [
            'productos' => $contents,
            ...$this->resizedVariants($contents, $mime),
        ];

        if ($this->shouldStoreInSpaces()) {
            try {
                foreach ($variants as $folder => $variantContents) {
                    Storage::disk('spaces')->put("images/{$folder}/{$filename}", $variantContents, [
                        'visibility' => 'public',
                        'ContentType' => $mime,
                        'CacheControl' => 'public, max-age=31536000, immutable',
                    ]);
                }

                return [
                    'storage' => 'spaces',
                    'storageLabel' => 'Spaces',
                ];
            } catch (Throwable) {
                // Fall through to local storage.
            }
        }

        foreach ($variants as $folder => $variantContents) {
            $directory = public_path("images/{$folder}");

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $variantContents);
        }

        return [
            'storage' => 'local',
            'storageLabel' => 'local',
        ];
    }

    private function normalizeDropboxUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        return str_replace('www.dropbox.com', 'dl.dropboxusercontent.com', str_replace('?dl=0', '', $url));
    }

    private function shouldStoreInSpaces(): bool
    {
        return filled(config('filesystems.disks.spaces.key'))
            && filled(config('filesystems.disks.spaces.secret'))
            && filled(config('filesystems.disks.spaces.bucket'))
            && filled(config('filesystems.disks.spaces.endpoint'))
            && filled(config('filesystems.disks.spaces.url'));
    }

    /**
     * @return array<string, string>
     */
    private function resizedVariants(string $contents, string $mime): array
    {
        $source = @imagecreatefromstring($contents);

        if (! $source) {
            throw new \RuntimeException('No se pudo leer la imagen para generar tamanos.');
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $variants = [];

            foreach (self::PHOTO_VARIANTS as $folder => $targetWidth) {
                $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

                if (in_array($mime, ['image/png', 'image/webp'], true)) {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefill($canvas, 0, 0, $transparent);
                }

                imagecopyresampled(
                    $canvas,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $sourceWidth,
                    $sourceHeight,
                );

                ob_start();
                match ($mime) {
                    'image/png' => imagepng($canvas, null, 7),
                    'image/webp' => function_exists('imagewebp') ? imagewebp($canvas, null, 82) : imagejpeg($canvas, null, 82),
                    default => imagejpeg($canvas, null, 82),
                };
                $variants[$folder] = (string) ob_get_clean();

                imagedestroy($canvas);
            }

            return $variants;
        } finally {
            imagedestroy($source);
        }
    }

    private function imageMime(string $contents): ?string
    {
        $info = @getimagesizefromstring($contents);

        return is_array($info) ? ($info['mime'] ?? null) : null;
    }

    private function normalizePhoto(object $photo): array
    {
        $filename = ltrim(trim((string) $photo->pfo_url), '/');

        return [
            'id' => (int) $photo->pfo_id,
            'filename' => $filename,
            'order' => (int) $photo->pfo_orden,
            'registeredAt' => $photo->pfo_fecha_registro,
            'isCover' => (bool) $photo->pfo_portada,
            'variants' => [
                [
                    'label' => 'Original',
                    'folder' => 'productos',
                    'width' => null,
                    'url' => $this->productImageUrl($filename, 'productos'),
                ],
                [
                    'label' => 'p100',
                    'folder' => 'p100',
                    'width' => 100,
                    'url' => $this->productImageUrl($filename, 'p100'),
                ],
                [
                    'label' => 'p200',
                    'folder' => 'p200',
                    'width' => 200,
                    'url' => $this->productImageUrl($filename, 'p200'),
                ],
                [
                    'label' => 'p400',
                    'folder' => 'p400',
                    'width' => 400,
                    'url' => $this->productImageUrl($filename, 'p400'),
                ],
                [
                    'label' => 'productos_thums',
                    'folder' => 'productos_thums',
                    'width' => 450,
                    'url' => $this->productImageUrl($filename, 'productos_thums'),
                ],
            ],
        ];
    }

    private function productImageUrl(string $filename, string $folder): string
    {
        $filename = trim($filename);

        if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
            return $filename;
        }

        $spacesUrl = rtrim((string) config('filesystems.disks.spaces.url'), '/');

        if ($spacesUrl !== '') {
            return $spacesUrl.'/images/'.$folder.'/'.ltrim($filename, '/');
        }

        return url('/images/'.$folder.'/'.ltrim($filename, '/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowLog(int $row, string $code, string $status, string $message): array
    {
        return [
            'row' => $row,
            'code' => $code,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function normalizeProduct(object $product): array
    {
        return [
            'id' => (int) $product->pro_id,
            'code' => (string) $product->pro_codigo,
            'name' => (string) $product->pro_nombre,
            'brand' => $product->pro_marca,
            'sizes' => (string) $product->pro_tallas,
            'collection' => $product->pro_coleccion,
            'character' => $product->pro_personaje,
            'status' => $product->pro_estatus,
            'thumb' => $product->pro_thumbs,
            'createdAt' => $product->pro_registro,
            'category' => [
                'id' => $product->cat_id !== null ? (int) $product->cat_id : null,
                'name' => $product->cat_nombre,
            ],
            'subcategory' => [
                'id' => $product->sca_id !== null ? (int) $product->sca_id : null,
                'name' => $product->sca_nombre,
            ],
        ];
    }
}
