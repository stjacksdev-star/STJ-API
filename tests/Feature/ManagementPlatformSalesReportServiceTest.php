<?php

namespace Tests\Feature;

use App\Services\Dashboard\ManagementPlatformSalesReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagementPlatformSalesReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table): void {
            $table->id('pai_id');
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });
        Schema::create('stj_pedidos', function (Blueprint $table): void {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_id_pais');
            $table->string('ped_origen')->nullable();
            $table->string('ped_plataforma')->nullable();
            $table->string('ped_checkout')->nullable();
            $table->string('ped_estatus')->nullable();
            $table->string('ped_nombres')->nullable();
            $table->string('ped_apellidos')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table): void {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->string('ppa_ref');
            $table->decimal('ppa_monto_senv');
            $table->dateTime('ppa_fecha');
        });

        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador'],
            ['pai_id' => 2, 'pai_codigo' => 'HN', 'pai_nombre' => 'Honduras'],
        ]);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 1, 'ped_id_pais' => 1, 'ped_origen' => 'WEB', 'ped_plataforma' => null, 'ped_checkout' => 'T', 'ped_estatus' => 'PROCESADO', 'ped_nombres' => 'Web', 'ped_apellidos' => 'Uno'],
            ['ped_id' => 2, 'ped_id_pais' => 1, 'ped_origen' => 'APP', 'ped_plataforma' => 'IOS', 'ped_checkout' => 'D', 'ped_estatus' => 'ANULADO', 'ped_nombres' => 'Ios', 'ped_apellidos' => 'Dos'],
            ['ped_id' => 3, 'ped_id_pais' => 1, 'ped_origen' => 'APP', 'ped_plataforma' => 'ANDROID', 'ped_checkout' => 'TIENDA', 'ped_estatus' => 'PENDIENTE', 'ped_nombres' => 'Android', 'ped_apellidos' => 'Tres'],
            ['ped_id' => 4, 'ped_id_pais' => 1, 'ped_origen' => 'APP', 'ped_plataforma' => null, 'ped_checkout' => 'D', 'ped_estatus' => 'FINALIZADO', 'ped_nombres' => 'Legacy', 'ped_apellidos' => 'Cuatro'],
            ['ped_id' => 5, 'ped_id_pais' => 1, 'ped_origen' => 'APP', 'ped_plataforma' => 'ANDROID', 'ped_checkout' => 'T', 'ped_estatus' => 'PROCESADO', 'ped_nombres' => 'Rechazado', 'ped_apellidos' => 'Cinco'],
            ['ped_id' => 6, 'ped_id_pais' => 2, 'ped_origen' => 'WEB', 'ped_plataforma' => null, 'ped_checkout' => 'T', 'ped_estatus' => 'PROCESADO', 'ped_nombres' => 'Otro', 'ped_apellidos' => 'Pais'],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_pedido' => 1, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'WEB-1', 'ppa_monto_senv' => 100, 'ppa_fecha' => '2026-10-01 08:00:00'],
            ['ppa_pedido' => 2, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'IOS-2', 'ppa_monto_senv' => 200, 'ppa_fecha' => '2026-10-01 09:00:00'],
            ['ppa_pedido' => 3, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'AND-3', 'ppa_monto_senv' => 300, 'ppa_fecha' => '2026-10-01 10:00:00'],
            ['ppa_pedido' => 4, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'OLD-4', 'ppa_monto_senv' => 50, 'ppa_fecha' => '2026-10-01 11:00:00'],
            ['ppa_pedido' => 5, 'ppa_estado' => 'RECHAZADA', 'ppa_ref' => 'NO-5', 'ppa_monto_senv' => 999, 'ppa_fecha' => '2026-10-01 12:00:00'],
            ['ppa_pedido' => 6, 'ppa_estado' => 'APROBADA', 'ppa_ref' => 'HN-6', 'ppa_monto_senv' => 800, 'ppa_fecha' => '2026-10-01 13:00:00'],
        ]);
    }

    public function test_report_groups_approved_payments_by_platform_without_excluding_order_statuses(): void
    {
        $report = app(ManagementPlatformSalesReportService::class)->report('SV', '2026-10-01', '2026-10-01');
        $indicators = collect($report['indicators'])->keyBy('platform');

        $this->assertSame(4, $report['totals']['orders']);
        $this->assertSame(650.0, $report['totals']['amount']);
        $this->assertSame(200.0, $indicators['APP-IOS']['amount']);
        $this->assertSame(300.0, $indicators['APP-ANDROID']['amount']);
        $this->assertSame(50.0, $indicators['APP-SIN-PLATAFORMA']['amount']);
        $this->assertSame('APP-ANDROID', $report['winner']['platform']);
        $this->assertTrue(collect($report['rows'])->contains(fn (array $row) => $row['platform'] === 'APP-IOS' && $row['type'] === 'DOMICILIO'));
    }

    public function test_order_detail_preserves_cancelled_approved_orders(): void
    {
        $detail = app(ManagementPlatformSalesReportService::class)->orders('1', '2026-10-01', '2026-10-01', 'APP-IOS', 'DOMICILIO');

        $this->assertSame(1, $detail['summary']['orders']);
        $this->assertSame(200.0, $detail['summary']['amount']);
        $this->assertSame('IOS-2', $detail['orders'][0]['reference']);
        $this->assertSame('ANULADO', $detail['orders'][0]['status']);
    }
}
