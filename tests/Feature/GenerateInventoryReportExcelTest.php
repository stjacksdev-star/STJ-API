<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class GenerateInventoryReportExcelTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputDirectory = storage_path('framework/testing/inventory-report-'.Str::uuid());
        config()->set('inventory_report.storage_path', $this->outputDirectory);
        config()->set('inventory_report.countries.SV.excel_name', 'El Salvador');
        $this->createSchema();
        $this->seedReport();
    }

    protected function tearDown(): void
    {
        if (str_starts_with($this->outputDirectory, storage_path('framework/testing/inventory-report-'))) {
            File::deleteDirectory($this->outputDirectory);
        }
        parent::tearDown();
    }

    public function test_it_generates_the_legacy_columns_and_numeric_inventory_cells(): void
    {
        $this->artisan('inventory-report:excel', ['--date' => '2026-09-23', '--country' => ['SV']])
            ->expectsOutputToContain('SV | GENERADO | Filas: 2')
            ->assertSuccessful();

        $run = DB::table('stj_inventory_report_runs')->first();
        $this->assertFileExists($run->irr_excel_path);
        $this->assertSame(hash_file('sha256', $run->irr_excel_path), $run->irr_excel_sha256);

        $spreadsheet = IOFactory::load($run->irr_excel_path);
        $sheet = $spreadsheet->getActiveSheet();
        $this->assertSame('Existencias de articulos e-commerce El Salvador', $sheet->getCell('A1')->getFormattedValue());
        $this->assertSame([
            'AÑO', 'TRIMESTRE', 'TIENDA', 'COLECCION', 'GENERO', 'MARCA', 'CATEGORIA',
            'LICENCIA', 'PERSONAJE', 'ESTILO', 'TALLA', 'DESCRIPCION', 'EXISTENCIA', 'PRECIO VTA',
        ], array_values(array_map(static fn ($cell): string => $cell->getFormattedValue(), [...$sheet->getRowIterator(4, 4)->current()->getCellIterator('A', 'N')])));
        $this->assertSame('P001', $sheet->getCell('J5')->getFormattedValue());
        $this->assertSame(7, $sheet->getCell('M5')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M5')->getDataType());
        $this->assertSame(0, $sheet->getCell('M6')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M6')->getDataType());
        $spreadsheet->disconnectWorksheets();
    }

    public function test_it_reuses_an_unchanged_generated_file(): void
    {
        $this->artisan('inventory-report:excel', ['--date' => '2026-09-23'])->assertSuccessful();

        $this->artisan('inventory-report:excel', ['--date' => '2026-09-23'])
            ->expectsOutputToContain('SV | VIGENTE | Filas: 2')
            ->assertSuccessful();
    }

    private function createSchema(): void
    {
        Schema::create('stj_inventory_report_runs', function (Blueprint $table): void {
            $table->id('irr_id');
            $table->date('irr_report_date');
            $table->unsignedBigInteger('irr_country_id');
            $table->string('irr_country_code');
            $table->string('irr_country_name');
            $table->string('irr_status');
            $table->unsignedBigInteger('irr_result_rows')->default(0);
            $table->string('irr_excel_path')->nullable();
            $table->string('irr_excel_sha256', 64)->nullable();
            $table->dateTime('irr_updated_at')->nullable();
        });
        Schema::create('stj_inventory_report_products', function (Blueprint $table): void {
            $table->id('irp_id');
            $table->unsignedBigInteger('irp_run_id');
            $table->string('irp_code');
            $table->string('irp_year')->nullable();
            $table->string('irp_quarter')->nullable();
            $table->string('irp_collection')->nullable();
            $table->string('irp_gender')->nullable();
            $table->string('irp_brand')->nullable();
            $table->string('irp_category')->nullable();
            $table->string('irp_license')->nullable();
            $table->string('irp_character')->nullable();
            $table->string('irp_description')->nullable();
        });
        Schema::create('stj_inventory_report_rows', function (Blueprint $table): void {
            $table->id('irw_id');
            $table->unsignedBigInteger('irw_run_id');
            $table->unsignedBigInteger('irw_product_id');
            $table->string('irw_store');
            $table->string('irw_size');
            $table->decimal('irw_quantity', 18, 4);
            $table->decimal('irw_sale_price', 18, 4)->nullable();
        });
    }

    private function seedReport(): void
    {
        DB::table('stj_inventory_report_runs')->insert([
            'irr_id' => 1, 'irr_report_date' => '2026-09-23', 'irr_country_id' => 1,
            'irr_country_code' => 'SV', 'irr_country_name' => 'El Salvador', 'irr_status' => 'COMPLETE',
            'irr_result_rows' => 2, 'irr_updated_at' => now(),
        ]);
        DB::table('stj_inventory_report_products')->insert([
            'irp_id' => 1, 'irp_run_id' => 1, 'irp_code' => 'P001', 'irp_year' => '2026', 'irp_quarter' => '3',
            'irp_collection' => 'Coleccion', 'irp_gender' => 'Niños', 'irp_brand' => 'ST JACKS',
            'irp_category' => 'Camisas', 'irp_license' => 'Licencia', 'irp_character' => 'Personaje',
            'irp_description' => 'Producto comercial',
        ]);
        DB::table('stj_inventory_report_rows')->insert([
            ['irw_id' => 1, 'irw_run_id' => 1, 'irw_product_id' => 1, 'irw_store' => 'Tienda 1', 'irw_size' => 'M', 'irw_quantity' => 7, 'irw_sale_price' => 19.95],
            ['irw_id' => 2, 'irw_run_id' => 1, 'irw_product_id' => 1, 'irw_store' => 'Tienda 2', 'irw_size' => 'L', 'irw_quantity' => 0, 'irw_sale_price' => 19.95],
        ]);
    }
}
