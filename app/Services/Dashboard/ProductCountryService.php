<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class ProductCountryService
{
    private const PRICE_COLUMNS = [
        'A' => 'codigo',
        'B' => 'leyenda',
        'C' => 'precio_talla',
        'D' => 'precio',
    ];

    private const SIZE_COLUMNS = [
        'A' => 'codigo',
        'B' => 'talla',
        'C' => 'precio',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_id')
            ->get()
            ->map(fn (object $country) => [
                'id' => (int) $country->pai_id,
                'code' => (string) $country->pai_codigo,
                'name' => (string) $country->pai_nombre,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file, int $countryId): array
    {
        $country = $this->country($countryId);
        $spreadsheet = $this->loadSpreadsheet($file);

        if ($spreadsheet->getSheetCount() < 2) {
            $spreadsheet->disconnectWorksheets();

            throw ValidationException::withMessages([
                'file' => 'El Excel debe tener dos hojas: alta de precios y alta de tallas.',
            ]);
        }

        $priceSheet = $spreadsheet->getSheet(0);
        $sizeSheet = $spreadsheet->getSheet(1);
        $priceRows = max(0, $this->highestDataRowInColumns($priceSheet, array_keys(self::PRICE_COLUMNS)) - 1);
        $sizeRows = max(0, $this->highestDataRowInColumns($sizeSheet, array_keys(self::SIZE_COLUMNS)) - 1);

        $summary = [
            'rows' => $priceRows + $sizeRows,
            'priceRows' => $priceRows,
            'sizeRows' => $sizeRows,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $log = [];

        $priceCodes = $this->codesFromSheet($priceSheet);
        $sizeCodes = $this->codesFromSheet($sizeSheet);
        $products = $this->productsByCode(array_values(array_unique([...$priceCodes, ...$sizeCodes])));
        $productIds = array_values(array_unique(array_map(
            static fn (object $product): int => (int) $product->pro_id,
            array_values($products),
        )));

        $countryProducts = $this->countryProductsByProduct((int) $country->pai_id, $productIds);
        $countrySizes = $this->countrySizesByProductAndSize((int) $country->pai_id, $productIds);

        $this->processPriceSheet($priceSheet, $country, $products, $countryProducts, $summary, $log);
        $this->processSizeSheet($sizeSheet, $country, $products, $countrySizes, $summary, $log);

        $spreadsheet->disconnectWorksheets();

        return [
            'country' => [
                'id' => (int) $country->pai_id,
                'code' => (string) $country->pai_codigo,
                'name' => (string) $country->pai_nombre,
            ],
            'summary' => $summary,
            'log' => array_slice($log, 0, 1000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(UploadedFile $file, int $countryId, string $reason): array
    {
        $country = $this->country($countryId);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de baja.',
            ]);
        }

        $spreadsheet = $this->loadDeactivateSpreadsheet($file);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $this->highestDataRowInColumns($sheet, ['A']);
        $summary = [
            'rows' => max(0, $highestRow - 1),
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $log = [];
        $products = $this->productsByCode($this->codesFromSheet($sheet));
        $countryProducts = $this->countryProductsByProduct(
            (int) $country->pai_id,
            array_values(array_unique(array_map(
                static fn (object $product): int => (int) $product->pro_id,
                array_values($products),
            )))
        );
        $validRows = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $code = $this->normalizeCode($this->cell($sheet, 'A', $row));

                if ($code === '') {
                    $summary['skipped']++;

                    continue;
                }

                $product = $products[$code] ?? null;

                if (! $product) {
                    $this->fail($summary, $log, 'Baja', $row, $code, 'No existe el producto.');

                    continue;
                }

                $existing = $countryProducts[(int) $product->pro_id] ?? null;

                if (! $existing) {
                    $this->fail($summary, $log, 'Baja', $row, $code, 'El producto no esta dado de alta para el pais seleccionado.');

                    continue;
                }

                $validRows[] = [
                    'id' => (int) $existing->ppa_id,
                    'row' => $row,
                    'code' => $code,
                ];
            } catch (Throwable $exception) {
                $this->fail($summary, $log, 'Baja', $row, $this->cell($sheet, 'A', $row), 'Error al inactivar producto: '.$exception->getMessage());
            }
        }

        $this->deactivateCountryProducts($validRows, $reason, (string) $country->pai_nombre, $summary, $log);
        $spreadsheet->disconnectWorksheets();

        return [
            'country' => [
                'id' => (int) $country->pai_id,
                'code' => (string) $country->pai_codigo,
                'name' => (string) $country->pai_nombre,
            ],
            'summary' => $summary,
            'log' => array_slice($log, 0, 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $log
     */
    private function processPriceSheet(Worksheet $sheet, object $country, array $products, array &$countryProducts, array &$summary, array &$log): void
    {
        $highestRow = $this->highestDataRowInColumns($sheet, array_keys(self::PRICE_COLUMNS));

        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $data = $this->readRow($sheet, $row, self::PRICE_COLUMNS);

                if ($data['codigo'] === '' && $data['precio'] === '') {
                    $summary['skipped']++;

                    continue;
                }

                if ($data['codigo'] === '') {
                    $this->fail($summary, $log, 'Precios', $row, '', 'Debe indicar codigo.');

                    continue;
                }

                $price = $this->decimal($data['precio']);

                if ($price <= 0) {
                    $this->fail($summary, $log, 'Precios', $row, $data['codigo'], 'El precio no puede ser cero o vacio.');

                    continue;
                }

                $priceBySize = $this->priceBySizeValue($data['precio_talla']);

                if ($priceBySize === null && trim($data['precio_talla']) !== '') {
                    $this->fail($summary, $log, 'Precios', $row, $data['codigo'], 'Precio talla debe ser SI, NO o vacio.');

                    continue;
                }

                $product = $products[$data['codigo']] ?? null;

                if (! $product) {
                    $this->fail($summary, $log, 'Precios', $row, $data['codigo'], 'No existe el producto.');

                    continue;
                }

                $existing = $countryProducts[(int) $product->pro_id] ?? null;
                $payload = [
                    'ppa_leyenda' => $data['leyenda'] !== '' ? mb_substr($data['leyenda'], 0, 100) : null,
                    'ppa_precio_talla' => $priceBySize,
                    'ppa_precio' => $price,
                    'ppa_estado' => 'ACTIVO',
                    'ppa_fecha_inactivo' => null,
                    'ppa_fecha_activo' => now(),
                ];

                if ($existing) {
                    DB::table('stj_producto_pais')
                        ->where('ppa_id', $existing->ppa_id)
                        ->update($payload);
                    $summary['updated']++;
                } else {
                    $id = DB::table('stj_producto_pais')->insertGetId([
                        ...$payload,
                        'ppa_pais' => (int) $country->pai_id,
                        'ppa_producto' => (int) $product->pro_id,
                        'ppa_envio_gratis' => 'NO',
                    ]);
                    $countryProducts[(int) $product->pro_id] = (object) ['ppa_id' => $id];
                    $summary['created']++;
                }

                $log[] = $this->rowLog('Precios', $row, $data['codigo'], $existing ? 'updated' : 'created', $existing ? 'Precio actualizado.' : 'Precio creado.');
            } catch (Throwable $exception) {
                $this->fail($summary, $log, 'Precios', $row, $this->cell($sheet, 'A', $row), 'Error al procesar precio: '.$exception->getMessage());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $log
     */
    private function processSizeSheet(Worksheet $sheet, object $country, array $products, array &$countrySizes, array &$summary, array &$log): void
    {
        $highestRow = $this->highestDataRowInColumns($sheet, array_keys(self::SIZE_COLUMNS));

        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $data = $this->readRow($sheet, $row, self::SIZE_COLUMNS);

                if ($data['codigo'] === '' && $data['talla'] === '' && $data['precio'] === '') {
                    $summary['skipped']++;

                    continue;
                }

                if ($data['codigo'] === '' || $data['talla'] === '') {
                    $this->fail($summary, $log, 'Tallas', $row, $data['codigo'], 'Debe indicar codigo y talla.');

                    continue;
                }

                $price = $this->decimal($data['precio']);

                if ($price <= 0) {
                    $this->fail($summary, $log, 'Tallas', $row, $data['codigo'], 'El precio no puede ser cero o vacio.');

                    continue;
                }

                $product = $products[$data['codigo']] ?? null;

                if (! $product) {
                    $this->fail($summary, $log, 'Tallas', $row, $data['codigo'], 'No existe el producto.');

                    continue;
                }

                $size = mb_substr(strtoupper(trim($data['talla'])), 0, 10);
                $key = $this->sizeKey((int) $product->pro_id, $size);
                $existing = $countrySizes[$key] ?? null;

                if ($existing) {
                    DB::table('stj_producto_talla')
                        ->where('pta_id', $existing->pta_id)
                        ->update(['pta_precio' => $price]);
                    $summary['updated']++;
                } else {
                    $id = DB::table('stj_producto_talla')->insertGetId([
                        'pta_pais' => (int) $country->pai_id,
                        'pta_producto' => (int) $product->pro_id,
                        'pta_talla' => $size,
                        'pta_precio' => $price,
                    ]);
                    $countrySizes[$key] = (object) ['pta_id' => $id];
                    $summary['created']++;
                }

                $log[] = $this->rowLog('Tallas', $row, $data['codigo'], $existing ? 'updated' : 'created', ($existing ? 'Talla actualizada: ' : 'Talla creada: ').$size.'.');
            } catch (Throwable $exception) {
                $this->fail($summary, $log, 'Tallas', $row, $this->cell($sheet, 'A', $row), 'Error al procesar talla: '.$exception->getMessage());
            }
        }
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    private function readRow(Worksheet $sheet, int $row, array $columns): array
    {
        $data = [];

        foreach ($columns as $column => $key) {
            $data[$key] = $this->cell($sheet, $column, $row);
        }

        $data['codigo'] = $this->normalizeCode($data['codigo'] ?? '');

        return $data;
    }

    private function country(int $countryId): object
    {
        $country = DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->where('pai_id', $countryId)
            ->first();

        if (! $country) {
            throw ValidationException::withMessages([
                'country' => 'El pais seleccionado no existe.',
            ]);
        }

        return $country;
    }

    /**
     * @return array<string, object>
     */
    private function productsByCode(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes)));

        if ($codes === []) {
            return [];
        }

        return DB::table('stj_productos')
            ->select(['pro_id', 'pro_codigo'])
            ->whereIn('pro_codigo', $codes)
            ->get()
            ->keyBy('pro_codigo')
            ->all();
    }

    /**
     * @return array<int, object>
     */
    private function countryProductsByProduct(int $countryId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));

        if ($productIds === []) {
            return [];
        }

        return DB::table('stj_producto_pais')
            ->select(['ppa_id', 'ppa_producto'])
            ->where('ppa_pais', $countryId)
            ->whereIn('ppa_producto', $productIds)
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->ppa_producto)
            ->all();
    }

    /**
     * @return array<string, object>
     */
    private function countrySizesByProductAndSize(int $countryId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));

        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('stj_producto_talla')
            ->select(['pta_id', 'pta_producto', 'pta_talla'])
            ->where('pta_pais', $countryId)
            ->whereIn('pta_producto', $productIds)
            ->get();

        $mapped = [];

        foreach ($rows as $row) {
            $mapped[$this->sizeKey((int) $row->pta_producto, (string) $row->pta_talla)] = $row;
        }

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function codesFromSheet(Worksheet $sheet): array
    {
        $codes = [];
        $highestRow = $sheet->getHighestDataRow('A');

        for ($row = 2; $row <= $highestRow; $row++) {
            $code = $this->normalizeCode($this->cell($sheet, 'A', $row));

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function highestDataRowInColumns(Worksheet $sheet, array $columns): int
    {
        $highestRow = 1;

        foreach ($columns as $column) {
            $highestRow = max($highestRow, $sheet->getHighestDataRow($column));
        }

        return $highestRow;
    }

    private function loadSpreadsheet(UploadedFile $file): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setIncludeCharts')) {
            $reader->setIncludeCharts(false);
        }

        return $reader->load($file->getRealPath());
    }

    private function loadDeactivateSpreadsheet(UploadedFile $file): Spreadsheet
    {
        $path = $file->getRealPath();
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $columnAddress === 'A';
            }
        });

        if (method_exists($reader, 'setIncludeCharts')) {
            $reader->setIncludeCharts(false);
        }

        $sheets = $reader->listWorksheetNames($path);

        if (($sheets[0] ?? null) !== null) {
            $reader->setLoadSheetsOnly($sheets[0]);
        }

        return $reader->load($path);
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        $cell = $sheet->getCell("{$column}{$row}");
        $value = $cell->getValue();

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

    private function decimal(string $value): float
    {
        $value = trim($value);

        if ($value === '') {
            return 0.0;
        }

        if (str_contains($value, ',') && ! str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function priceBySizeValue(string $value): ?string
    {
        $value = strtoupper(trim($value));

        if ($value === '') {
            return null;
        }

        return in_array($value, ['SI', 'NO'], true) ? $value : null;
    }

    private function sizeKey(int $productId, string $size): string
    {
        return $productId.'|'.mb_substr(strtoupper(trim($size)), 0, 10);
    }

    /**
     * @param  array<int, array{id: int, row: int, code: string}>  $validRows
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $log
     */
    private function deactivateCountryProducts(array $validRows, string $reason, string $countryName, array &$summary, array &$log): void
    {
        if ($validRows === []) {
            return;
        }

        $payload = [
            'ppa_estado' => 'INACTIVO',
            'ppa_fecha_inactivo' => now(),
            'ppa_inactivo_motivo' => mb_substr($reason, 0, 100),
        ];

        foreach (array_chunk(array_values(array_unique(array_column($validRows, 'id'))), 500) as $ids) {
            DB::table('stj_producto_pais')
                ->whereIn('ppa_id', $ids)
                ->update($payload);
        }

        foreach ($validRows as $row) {
            $summary['updated']++;
            $log[] = $this->rowLog('Baja', $row['row'], $row['code'], 'updated', 'Producto inactivado para '.$countryName.'.');
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $log
     */
    private function fail(array &$summary, array &$log, string $sheet, int $row, string $code, string $message): void
    {
        $summary['failed']++;
        $log[] = $this->rowLog($sheet, $row, $code, 'error', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowLog(string $sheet, int $row, string $code, string $status, string $message): array
    {
        return [
            'sheet' => $sheet,
            'row' => $row,
            'code' => $code,
            'status' => $status,
            'message' => $message,
        ];
    }
}
