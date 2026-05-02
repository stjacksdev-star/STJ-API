<?php

namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $spreadsheet = IOFactory::load($file->getRealPath());

        if ($spreadsheet->getSheetCount() < 2) {
            $spreadsheet->disconnectWorksheets();

            throw ValidationException::withMessages([
                'file' => 'El Excel debe tener dos hojas: alta de precios y alta de tallas.',
            ]);
        }

        $priceSheet = $spreadsheet->getSheet(0);
        $sizeSheet = $spreadsheet->getSheet(1);

        $summary = [
            'rows' => max(0, $priceSheet->getHighestDataRow() - 1) + max(0, $sizeSheet->getHighestDataRow() - 1),
            'priceRows' => max(0, $priceSheet->getHighestDataRow() - 1),
            'sizeRows' => max(0, $sizeSheet->getHighestDataRow() - 1),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $log = [];

        $this->processPriceSheet($priceSheet, $country, $summary, $log);
        $this->processSizeSheet($sizeSheet, $country, $summary, $log);

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

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestDataRow();
        $summary = [
            'rows' => max(0, $highestRow - 1),
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $log = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            try {
                $code = $this->normalizeCode($this->cell($sheet, 'A', $row));

                if ($code === '') {
                    $summary['skipped']++;
                    continue;
                }

                $product = $this->product($code);

                if (! $product) {
                    $this->fail($summary, $log, 'Baja', $row, $code, 'No existe el producto.');
                    continue;
                }

                $existing = DB::table('stj_producto_pais')
                    ->where('ppa_producto', $product->pro_id)
                    ->where('ppa_pais', $country->pai_id)
                    ->first();

                if (! $existing) {
                    $this->fail($summary, $log, 'Baja', $row, $code, 'El producto no esta dado de alta para el pais seleccionado.');
                    continue;
                }

                DB::table('stj_producto_pais')
                    ->where('ppa_id', $existing->ppa_id)
                    ->update([
                        'ppa_estado' => 'INACTIVO',
                        'ppa_fecha_inactivo' => now(),
                        'ppa_inactivo_motivo' => mb_substr($reason, 0, 100),
                    ]);

                $summary['updated']++;
                $log[] = $this->rowLog('Baja', $row, $code, 'updated', 'Producto inactivado para '.$country->pai_nombre.'.');
            } catch (Throwable $exception) {
                $this->fail($summary, $log, 'Baja', $row, $this->cell($sheet, 'A', $row), 'Error al inactivar producto: '.$exception->getMessage());
            }
        }

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
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $log
     */
    private function processPriceSheet(Worksheet $sheet, object $country, array &$summary, array &$log): void
    {
        $highestRow = $sheet->getHighestDataRow();

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

                $product = $this->product($data['codigo']);

                if (! $product) {
                    $this->fail($summary, $log, 'Precios', $row, $data['codigo'], 'No existe el producto.');
                    continue;
                }

                $existing = DB::table('stj_producto_pais')
                    ->where('ppa_producto', $product->pro_id)
                    ->where('ppa_pais', $country->pai_id)
                    ->first();
                $payload = [
                    'ppa_leyenda' => $data['leyenda'] !== '' ? mb_substr($data['leyenda'], 0, 100) : null,
                    'ppa_precio_talla' => $priceBySize,
                    'ppa_precio' => $price,
                    'ppa_estado' => 'ACTIVO',
                    'ppa_fecha_inactivo' => null,
                    'ppa_fecha_activo' => now(),
                ];

                DB::transaction(function () use ($existing, $product, $country, $payload, &$summary): void {
                    if ($existing) {
                        DB::table('stj_producto_pais')
                            ->where('ppa_id', $existing->ppa_id)
                            ->update($payload);
                        $summary['updated']++;
                    } else {
                        DB::table('stj_producto_pais')->insert([
                            ...$payload,
                            'ppa_pais' => (int) $country->pai_id,
                            'ppa_producto' => (int) $product->pro_id,
                            'ppa_envio_gratis' => 'NO',
                        ]);
                        $summary['created']++;
                    }
                });

                $log[] = $this->rowLog('Precios', $row, $data['codigo'], $existing ? 'updated' : 'created', $existing ? 'Precio actualizado.' : 'Precio creado.');
            } catch (Throwable $exception) {
                $this->fail($summary, $log, 'Precios', $row, $this->cell($sheet, 'A', $row), 'Error al procesar precio: '.$exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $log
     */
    private function processSizeSheet(Worksheet $sheet, object $country, array &$summary, array &$log): void
    {
        $highestRow = $sheet->getHighestDataRow();

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

                $product = $this->product($data['codigo']);

                if (! $product) {
                    $this->fail($summary, $log, 'Tallas', $row, $data['codigo'], 'No existe el producto.');
                    continue;
                }

                $size = mb_substr(strtoupper(trim($data['talla'])), 0, 10);
                $existing = DB::table('stj_producto_talla')
                    ->where('pta_producto', $product->pro_id)
                    ->where('pta_pais', $country->pai_id)
                    ->where('pta_talla', $size)
                    ->first();

                DB::transaction(function () use ($existing, $product, $country, $size, $price, &$summary): void {
                    if ($existing) {
                        DB::table('stj_producto_talla')
                            ->where('pta_id', $existing->pta_id)
                            ->update(['pta_precio' => $price]);
                        $summary['updated']++;
                    } else {
                        DB::table('stj_producto_talla')->insert([
                            'pta_pais' => (int) $country->pai_id,
                            'pta_producto' => (int) $product->pro_id,
                            'pta_talla' => $size,
                            'pta_precio' => $price,
                        ]);
                        $summary['created']++;
                    }
                });

                $log[] = $this->rowLog('Tallas', $row, $data['codigo'], $existing ? 'updated' : 'created', ($existing ? 'Talla actualizada: ' : 'Talla creada: ').$size.'.');
            } catch (Throwable $exception) {
                $this->fail($summary, $log, 'Tallas', $row, $this->cell($sheet, 'A', $row), 'Error al procesar talla: '.$exception->getMessage());
            }
        }
    }

    /**
     * @param array<string, string> $columns
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

    private function product(string $code): ?object
    {
        return DB::table('stj_productos')
            ->select(['pro_id', 'pro_codigo'])
            ->where('pro_codigo', $code)
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

    /**
     * @param array<string, mixed> $summary
     * @param array<int, array<string, mixed>> $log
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
