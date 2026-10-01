<?php

namespace Tests\Feature;

use App\Services\Dashboard\ManagementDailySalesReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ManagementDailySalesReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rep_bihoral_ec', function (Blueprint $table): void {
            $table->id();
            $table->decimal('Total');
            $table->unsignedBigInteger('Pais');
            $table->dateTime('Fecha');
        });
        Schema::create('stj_pedidos', function (Blueprint $table): void {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_id_pais');
            $table->string('ped_tienda');
        });
        Schema::create('stj_tiendas', function (Blueprint $table): void {
            $table->id('tie_id');
            $table->unsignedBigInteger('tie_pais');
            $table->string('tie_codigo');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table): void {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->decimal('ppa_monto_senv');
            $table->string('ppa_estado');
            $table->dateTime('ppa_fecha');
        });
        Schema::create('tasa_hnl_usd', function (Blueprint $table): void {
            $table->id();
            $table->decimal('tasa', 12, 8);
            $table->date('fecha');
        });

        DB::table('tasa_hnl_usd')->insert(['tasa' => 0.04, 'fecha' => '2026-01-01']);
        DB::table('rep_bihoral_ec')->insert([
            ['Total' => 100, 'Pais' => 1, 'Fecha' => '2023-10-05 10:00:00'],
            ['Total' => 100, 'Pais' => 2, 'Fecha' => '2024-10-05 10:00:00'],
        ]);
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 1, 'tie_pais' => 3, 'tie_codigo' => '001'],
            ['tie_id' => 2, 'tie_pais' => 7, 'tie_codigo' => '002'],
        ]);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 1, 'ped_id_pais' => 3, 'ped_tienda' => '001'],
            ['ped_id' => 2, 'ped_id_pais' => 7, 'ped_tienda' => '002'],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_pedido' => 1, 'ppa_monto_senv' => 1000, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2025-10-05 12:00:00'],
            ['ppa_pedido' => 2, 'ppa_monto_senv' => 1000, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2026-10-05 12:00:00'],
        ]);
    }

    public function test_report_combines_legacy_and_current_sales_in_usd_by_day(): void
    {
        $report = app(ManagementDailySalesReportService::class)->report(10);

        $this->assertCount(1, $report['rows']);
        $this->assertSame(100.0, $report['rows'][0]['years'][2023]);
        $this->assertSame(13.05, $report['rows'][0]['years'][2024]);
        $this->assertSame(1.76, $report['rows'][0]['years'][2025]);
        $this->assertSame(40.0, $report['rows'][0]['years'][2026]);
        $this->assertSame(154.81, $report['rows'][0]['grandTotal']);
    }

    public function test_export_keeps_legacy_column_order(): void
    {
        $file = app(ManagementDailySalesReportService::class)->export(10);
        $path = tempnam(sys_get_temp_dir(), 'gere_').'.xls';
        file_put_contents($path, $file['contents']);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame(
                ['Dia', '2023', '2024', '2025', '2026', 'Grand Total', '2026 - 2025', '2026 - 2024', '2026 - 2023'],
                $sheet->rangeToArray('A1:I1')[0],
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_monthly_report_supports_all_months_and_country_filter_with_totals(): void
    {
        DB::table('rep_bihoral_ec')->insert(['Total' => 50, 'Pais' => 1, 'Fecha' => '2023-11-02 10:00:00']);
        $service = app(ManagementDailySalesReportService::class);

        $all = $service->monthlyReport(0, 0);
        $sv = $service->monthlyReport(0, 1);

        $this->assertSame(['Octubre', 'Noviembre'], collect($all['rows'])->pluck('monthName')->all());
        $this->assertSame(150.0, $all['totals']['years'][2023]);
        $this->assertSame(150.0, $sv['totals']['years'][2023]);
        $this->assertSame(0.0, $sv['totals']['years'][2024]);
        $this->assertSame(38.24, $all['totals']['growth']);
    }

    public function test_monthly_export_keeps_legacy_title_filters_headers_and_totals(): void
    {
        $file = app(ManagementDailySalesReportService::class)->exportMonthly(10, 2);
        $path = tempnam(sys_get_temp_dir(), 'gere_month_').'.xlsx';
        file_put_contents($path, $file['contents']);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('Reporte Ventas EC por Mes', $sheet->getCell('A1')->getValue());
            $this->assertSame('Pais: Guatemala | Mes: 10', $sheet->getCell('A2')->getValue());
            $this->assertSame(['Mes', '2023', '2024', '2025', '2026', 'Crecimiento 2026 vs 2025'], $sheet->rangeToArray('A3:F3')[0]);
            $this->assertSame('Totales', $sheet->getCell('A5')->getValue());
            $this->assertSame('reporte_ventas_ec_mensual_mes10_pais2.xlsx', $file['filename']);
        } finally {
            @unlink($path);
        }
    }
}
