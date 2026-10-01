<?php

namespace Tests\Feature;

use App\Services\Dashboard\ManagementCyberMondayReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ManagementCyberMondayReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_pedidos', function (Blueprint $table): void {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_id_pais');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table): void {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->decimal('ppa_monto_senv');
            $table->dateTime('ppa_fecha');
        });

        DB::table('stj_pedidos')->insert([
            ['ped_id' => 1, 'ped_id_pais' => 1],
            ['ped_id' => 2, 'ped_id_pais' => 1],
            ['ped_id' => 3, 'ped_id_pais' => 2],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_pedido' => 1, 'ppa_estado' => 'APROBADA', 'ppa_monto_senv' => 100, 'ppa_fecha' => '2025-11-30 10:15:00'],
            ['ppa_pedido' => 1, 'ppa_estado' => 'APROBADA', 'ppa_monto_senv' => 150, 'ppa_fecha' => '2026-11-29 10:30:00'],
            ['ppa_pedido' => 2, 'ppa_estado' => 'APROBADA', 'ppa_monto_senv' => 50, 'ppa_fecha' => '2026-11-29 10:45:00'],
            ['ppa_pedido' => 2, 'ppa_estado' => 'APROBADA', 'ppa_monto_senv' => 80, 'ppa_fecha' => '2025-12-01 08:00:00'],
            ['ppa_pedido' => 2, 'ppa_estado' => 'APROBADA', 'ppa_monto_senv' => 40, 'ppa_fecha' => '2026-11-30 08:00:00'],
            ['ppa_pedido' => 3, 'ppa_estado' => 'APROBADA', 'ppa_monto_senv' => 999, 'ppa_fecha' => '2026-11-29 10:00:00'],
        ]);
    }

    public function test_report_compares_2026_against_configured_2025_dates_by_hour_and_country(): void
    {
        $report = app(ManagementCyberMondayReportService::class)->report(1);
        $sunday = $report['periods']['sunday'];
        $monday = $report['periods']['monday'];
        $hour10 = $sunday['rows'][10];

        $this->assertSame('2025-11-30', $sunday['previousDate']);
        $this->assertSame('2026-11-29', $sunday['currentDate']);
        $this->assertSame(1, $hour10['previousOrders']);
        $this->assertSame(2, $hour10['currentOrders']);
        $this->assertSame(100.0, $hour10['previousAmount']);
        $this->assertSame(200.0, $hour10['currentAmount']);
        $this->assertSame(100.0, $hour10['amountPercentage']);
        $this->assertSame('2025-12-01', $monday['previousDate']);
        $this->assertSame('2026-11-30', $monday['currentDate']);
        $this->assertSame(-40.0, $monday['totals']['amountDifference']);
    }

    public function test_export_contains_both_2025_vs_2026_sections(): void
    {
        $file = app(ManagementCyberMondayReportService::class)->export(1);
        $path = tempnam(sys_get_temp_dir(), 'cyber_').'.xls';
        file_put_contents($path, $file['contents']);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $values = $sheet->rangeToArray('A1:I60');
            $flat = collect($values)->flatten()->filter()->all();
            $this->assertContains('DOMINGO - 2025 vs 2026', $flat);
            $this->assertContains('LUNES - 2025 vs 2026', $flat);
            $this->assertNotContains('2024 Pedidos', $flat);
            $this->assertSame('reporte_horas_cyber.xls', $file['filename']);
        } finally {
            @unlink($path);
        }
    }
}
