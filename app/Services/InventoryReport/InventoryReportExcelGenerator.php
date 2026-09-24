<?php

namespace App\Services\InventoryReport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

class InventoryReportExcelGenerator
{
    private const MAX_XLSX_ROWS = 1_048_576;

    private const HEADERS = [
        'AÑO',
        'TRIMESTRE',
        'TIENDA',
        'COLECCION',
        'GENERO',
        'MARCA',
        'CATEGORIA',
        'LICENCIA',
        'PERSONAJE',
        'ESTILO',
        'TALLA',
        'DESCRIPCION',
        'EXISTENCIA',
        'PRECIO VTA',
    ];

    /** @return array<string, mixed> */
    public function generate(int $runId, bool $force = false): array
    {
        $run = DB::table('stj_inventory_report_runs')->where('irr_id', $runId)->first();
        if ($run === null) {
            throw new RuntimeException("No existe la corrida {$runId}.");
        }
        if (! in_array((string) $run->irr_status, ['COMPLETE', 'PARTIAL'], true)) {
            throw new RuntimeException("La corrida {$runId} todavia no esta cerrada; estado actual: {$run->irr_status}.");
        }

        $existingPath = trim((string) ($run->irr_excel_path ?? ''));
        $existingHash = trim((string) ($run->irr_excel_sha256 ?? ''));
        if (! $force && $existingPath !== '' && is_file($existingPath) && $existingHash !== '' && hash_file('sha256', $existingPath) === $existingHash) {
            return $this->summary($run, $existingPath, $existingHash, false);
        }

        $rowCount = DB::table('stj_inventory_report_rows')->where('irw_run_id', $runId)->count();
        if ($rowCount + 4 > self::MAX_XLSX_ROWS) {
            throw new RuntimeException("La corrida {$runId} contiene {$rowCount} filas y excede el limite de una hoja XLSX.");
        }

        $directory = $this->directory((string) $run->irr_report_date);
        File::ensureDirectoryExists($directory);
        $displayName = (string) config("inventory_report.countries.{$run->irr_country_code}.excel_name", $run->irr_country_name);
        $filename = "ExistenciasECommerce - {$displayName}.xlsx";
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $temporaryPath = $directory.DIRECTORY_SEPARATOR.'.'.pathinfo($filename, PATHINFO_FILENAME).'.'.uniqid('', true).'.tmp.xlsx';

        $defaultStyle = new Style(fontSize: 11, fontName: 'Arial');
        $options = new Options(FALLBACK_STYLE: $defaultStyle, tempFolder: storage_path('framework/cache'));
        $options->setColumnWidthForRange(14, 1, 14);
        $options->setColumnWidth(35, 3, 12);
        $writer = new Writer($options);

        try {
            $writer->openToFile($temporaryPath);
            $writer->getCurrentSheet()->setName(mb_substr($displayName, 0, 31));
            $writer->addRow(Row::fromValuesWithStyle(
                ["Existencias de articulos e-commerce {$displayName}", ...array_fill(0, 13, '')],
                new Style(fontBold: true, fontSize: 20, fontName: 'Arial'),
            ));
            $writer->addRow(Row::fromValues([now((string) config('inventory_report.timezone'))->format('d/m/Y h:i:s a'), ...array_fill(0, 13, '')]));
            $writer->addRow(Row::fromValues(array_fill(0, 14, '')));
            $writer->addRow(Row::fromValuesWithStyle(self::HEADERS, new Style(fontBold: true, fontSize: 11, fontName: 'Arial')));

            $this->rows($runId)->orderBy('row.irw_id')->chunk(1000, function ($rows) use ($writer): void {
                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues([
                        $this->text($row->year),
                        $this->text($row->quarter),
                        $this->text($row->store),
                        $this->text($row->collection),
                        $this->text($row->gender),
                        $this->text($row->brand),
                        $this->text($row->category),
                        $this->text($row->license),
                        $this->text($row->character),
                        $this->text($row->code),
                        $this->text($row->size),
                        $this->text($row->description),
                        $this->numeric($row->quantity),
                        $this->text($row->sale_price),
                    ]));
                }
            });
            $writer->close();

            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException("No fue posible reemplazar el Excel existente: {$path}.");
            }
            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException("No fue posible publicar el Excel generado: {$path}.");
            }
        } catch (\Throwable $exception) {
            try {
                $writer->close();
            } catch (\Throwable) {
                // El escritor puede no haberse abierto o ya estar cerrado.
            }
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            throw $exception;
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException("No fue posible calcular el hash del Excel {$path}.");
        }
        DB::table('stj_inventory_report_runs')->where('irr_id', $runId)->update([
            'irr_excel_path' => $path,
            'irr_excel_sha256' => $hash,
            'irr_updated_at' => now(),
        ]);
        $run->irr_result_rows = $rowCount;

        return $this->summary($run, $path, $hash, true);
    }

    private function rows(int $runId)
    {
        return DB::table('stj_inventory_report_rows as row')
            ->join('stj_inventory_report_products as product', 'product.irp_id', '=', 'row.irw_product_id')
            ->where('row.irw_run_id', $runId)
            ->select([
                'row.irw_id',
                'product.irp_year as year',
                'product.irp_quarter as quarter',
                'row.irw_store as store',
                'product.irp_collection as collection',
                'product.irp_gender as gender',
                'product.irp_brand as brand',
                'product.irp_category as category',
                'product.irp_license as license',
                'product.irp_character as character',
                'product.irp_code as code',
                'row.irw_size as size',
                'product.irp_description as description',
                'row.irw_quantity as quantity',
                'row.irw_sale_price as sale_price',
            ]);
    }

    private function directory(string $date): string
    {
        $base = trim((string) config('inventory_report.storage_path'));
        if ($base === '') {
            $base = storage_path('app/private/inventory-reports');
        }

        return rtrim($base, '\\/').DIRECTORY_SEPARATOR.$date;
    }

    private function text(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function numeric(mixed $value): int|float
    {
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }

    /** @return array<string, mixed> */
    private function summary(object $run, string $path, string $hash, bool $generated): array
    {
        return [
            'runId' => (int) $run->irr_id,
            'countryCode' => (string) $run->irr_country_code,
            'countryName' => (string) $run->irr_country_name,
            'status' => (string) $run->irr_status,
            'rows' => (int) $run->irr_result_rows,
            'path' => $path,
            'sha256' => $hash,
            'generated' => $generated,
        ];
    }
}
