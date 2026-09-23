<?php

namespace App\Services\InventoryReport;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryReportRunStarter
{
    /**
     * @return array{country_code: string, country_name: string, report_date: string, run_id: int|null, products: int, created: bool, dry_run: bool}
     */
    public function start(string $countryCode, Carbon $reportDate, bool $dryRun = false): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $configured = config("inventory_report.countries.{$countryCode}");
        if (! is_array($configured)) {
            throw new InvalidArgumentException("Pais no soportado para el reporte: {$countryCode}.");
        }

        $country = DB::table('stj_paises')
            ->where('pai_id', (int) $configured['id'])
            ->whereRaw('UPPER(pai_codigo) = ?', [$countryCode])
            ->where('pai_estado', 'ACTIVO')
            ->first(['pai_id', 'pai_codigo', 'pai_nombre']);
        if ($country === null) {
            throw new InvalidArgumentException("El pais {$countryCode} no existe, no coincide con su configuracion o no esta activo.");
        }

        $date = $reportDate->toDateString();
        $existing = $this->existingRun($date, (int) $country->pai_id);
        if ($existing !== null) {
            return $this->summary($existing, false, false);
        }

        $products = $this->products((int) $country->pai_id);
        $productCount = (clone $products)->count();
        if ($dryRun) {
            return [
                'country_code' => $countryCode,
                'country_name' => (string) $country->pai_nombre,
                'report_date' => $date,
                'run_id' => null,
                'products' => $productCount,
                'created' => false,
                'dry_run' => true,
            ];
        }

        return DB::transaction(function () use ($country, $countryCode, $date, $products): array {
            $inserted = DB::table('stj_inventory_report_runs')->insertOrIgnore([
                'irr_report_date' => $date,
                'irr_country_id' => (int) $country->pai_id,
                'irr_country_code' => $countryCode,
                'irr_country_name' => (string) $country->pai_nombre,
                'irr_status' => 'CREATED',
                'irr_started_at' => now(),
                'irr_created_at' => now(),
                'irr_updated_at' => now(),
            ]);

            $run = $this->existingRun($date, (int) $country->pai_id, true);
            if ($run === null) {
                throw new InvalidArgumentException("No fue posible crear ni recuperar la corrida de {$countryCode}.");
            }

            // Otra ejecucion pudo crear la corrida antes de adquirir el bloqueo.
            // Incluso una corrida legitima de cero productos debe permanecer inmutable.
            if ($inserted === 0) {
                return $this->summary($run, false, false);
            }

            $count = 0;
            $products->orderBy('product.pro_id')->chunk(500, function ($chunk) use ($run, &$count): void {
                $timestamp = now();
                $rows = $chunk->map(static fn (object $product): array => [
                    'irp_run_id' => (int) $run->irr_id,
                    'irp_product_id' => (int) $product->product_id,
                    'irp_code' => trim((string) $product->product_code),
                    'irp_category_id' => $product->category_id === null ? null : (int) $product->category_id,
                    'irp_year' => $product->year === null ? null : (string) $product->year,
                    'irp_quarter' => $product->quarter === null ? null : (string) $product->quarter,
                    'irp_collection' => $product->collection,
                    'irp_gender' => $product->gender,
                    'irp_brand' => $product->brand,
                    'irp_category' => $product->category,
                    'irp_license' => $product->license,
                    'irp_character' => $product->character,
                    'irp_description' => $product->description,
                    'irp_status' => 'PENDING',
                    'irp_attempts' => 0,
                    'irp_created_at' => $timestamp,
                    'irp_updated_at' => $timestamp,
                ])->all();

                if ($rows !== []) {
                    DB::table('stj_inventory_report_products')->insert($rows);
                    $count += count($rows);
                }
            });

            DB::table('stj_inventory_report_runs')
                ->where('irr_id', $run->irr_id)
                ->update([
                    'irr_expected_products' => $count,
                    'irr_updated_at' => now(),
                ]);

            $run->irr_expected_products = $count;

            return $this->summary($run, true, false);
        }, 3);
    }

    private function products(int $countryId): Builder
    {
        return DB::table('stj_productos as product')
            ->join('stj_producto_pais as country_product', 'country_product.ppa_producto', '=', 'product.pro_id')
            ->where('country_product.ppa_pais', $countryId)
            ->where('country_product.ppa_estado', 'ACTIVO')
            ->where('product.pro_estatus', 'ACTIVO')
            ->whereNotNull('product.pro_codigo')
            ->where('product.pro_codigo', '<>', '')
            ->select([
                'product.pro_id as product_id',
                'product.pro_codigo as product_code',
                'product.pro_categoria as category_id',
                'product.pro_oc_anio as year',
                'product.pro_oc_trimestre as quarter',
                'product.pro_oc_coleccion as collection',
                'product.pro_oc_genero as gender',
                'product.pro_oc_marca as brand',
                'product.pro_oc_categoria as category',
                'product.pro_oc_licencia as license',
                'product.pro_oc_personaje as character',
                'product.pro_nombre as description',
            ]);
    }

    private function existingRun(string $date, int $countryId, bool $lock = false): ?object
    {
        $query = DB::table('stj_inventory_report_runs')
            ->where('irr_report_date', $date)
            ->where('irr_country_id', $countryId);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @return array{country_code: string, country_name: string, report_date: string, run_id: int, products: int, created: bool, dry_run: bool}
     */
    private function summary(object $run, bool $created, bool $dryRun): array
    {
        return [
            'country_code' => (string) $run->irr_country_code,
            'country_name' => (string) $run->irr_country_name,
            'report_date' => Carbon::parse($run->irr_report_date)->toDateString(),
            'run_id' => (int) $run->irr_id,
            'products' => (int) $run->irr_expected_products,
            'created' => $created,
            'dry_run' => $dryRun,
        ];
    }
}
