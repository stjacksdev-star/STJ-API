<?php

namespace Tests\Feature;

use App\Services\DailySalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailySalesReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_id_pais');
            $table->string('ped_origen');
            $table->decimal('ped_monto_devolucion', 12, 2)->default(0);
            $table->string('ped_devolucion_realizada')->default('N/A');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->string('ppa_tipo');
            $table->decimal('ppa_monto_senv', 12, 2);
            $table->dateTime('ppa_fecha');
        });
        Schema::create('tasa_hnl_usd', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('tasa', 12, 7);
        });

        config()->set('daily_sales_report.to', ['ventas@stjacks.com']);
        config()->set('daily_sales_report.cc', []);
        config()->set('daily_sales_report.bcc', ['auditoria@stjacks.com']);
        config()->set('daily_sales_report.timezone', 'America/El_Salvador');
        config()->set('daily_sales_report.gtq_usd_rate', 0.1);
        config()->set('daily_sales_report.crc_usd_rate', 0.002);
        config()->set('services.smtp2go.key', 'test-key');
        config()->set('services.smtp2go.sender', 'no-reply@example.test');
        Http::fake(['*' => Http::response(['data' => ['failed' => 0]], 200)]);

        DB::table('tasa_hnl_usd')->insert([
            ['id' => 1, 'fecha' => '2026-09-10', 'tasa' => 0.04],
            ['id' => 2, 'fecha' => '2026-09-16', 'tasa' => 0.05],
        ]);

        $this->order(1, 1, 'WEB', 100, 'TARJETA', '2026-09-14 09:00:00', 10);
        $this->order(2, 1, 'APP', 50, 'EFECTIVO', '2026-09-14 10:00:00');
        $this->order(3, 2, 'WEB', 100, 'TARJETA', '2026-09-14 11:00:00');
        $this->order(4, 3, 'APP', 1000, 'TARJETA', '2026-09-14 12:00:00');
        $this->order(5, 7, 'WEB', 100, 'EFECTIVO', '2026-09-14 13:00:00');
        $this->order(6, 5, 'WEB', 25, 'TARJETA', '2026-09-14 14:00:00');
        $this->order(7, 5, 'APP', 999, 'TARJETA', '2026-09-14 15:00:00');
        $this->order(8, 1, 'WEB', 20, 'TARJETA', '2026-09-01 08:00:00');
        $this->order(9, 1, 'WEB', 500, 'TARJETA', '2026-08-31 08:00:00');
        $this->order(10, 1, 'WEB', 500, 'TARJETA', '2026-09-14 08:00:00', 0, 'DENEGADA');
    }

    public function test_it_builds_the_five_country_report_in_usd_with_web_and_app_columns(): void
    {
        $report = app(DailySalesReportService::class)->generate(CarbonImmutable::parse('2026-09-14', 'America/El_Salvador'));

        $this->assertSame(100.0, $report['sales']['daily']['countries']['sv']['web']);
        $this->assertSame(50.0, $report['sales']['daily']['countries']['sv']['app']);
        $this->assertSame(10.0, $report['sales']['daily']['countries']['gt']['web']);
        $this->assertSame(2.0, $report['sales']['daily']['countries']['cr']['app']);
        $this->assertSame(4.0, $report['sales']['daily']['countries']['hn']['web']);
        $this->assertSame(25.0, $report['sales']['daily']['countries']['pa']['web']);
        $this->assertSame(0.0, $report['sales']['daily']['countries']['pa']['app']);
        $this->assertSame(191.0, $report['sales']['daily']['total']);
        $this->assertSame(211.0, $report['sales']['month_to_date']['total']);
        $this->assertSame(10.0, $report['refunds']['daily']['countries']['sv']['web']);
        $this->assertSame(137.0, $report['payment_types']['TARJETA']['total']);
        $this->assertSame(54.0, $report['payment_types']['EFECTIVO']['total']);
        $this->assertSame(0.04, $report['rates']['hnl_usd']);
    }

    public function test_it_sends_the_report_to_configured_recipients(): void
    {
        $summary = app(DailySalesReportService::class)->send(CarbonImmutable::parse('2026-09-14', 'America/El_Salvador'));

        $this->assertTrue($summary['sent']);
        Http::assertSent(function ($request): bool {
            return $request['to'] === ['ventas@stjacks.com']
                && $request['bcc'] === ['auditoria@stjacks.com']
                && str_contains($request['subject'], '2026-09-14')
                && str_contains($request['html_body'], 'El Salvador Web')
                && str_contains($request['html_body'], 'Panamá App')
                && str_contains($request['html_body'], 'VENTA ACUMULADA DEL MES');
        });
    }

    private function order(int $id, int $country, string $origin, float $amount, string $paymentType, string $date, float $refund = 0, string $paymentStatus = 'APROBADA'): void
    {
        DB::table('stj_pedidos')->insert([
            'ped_id' => $id,
            'ped_id_pais' => $country,
            'ped_origen' => $origin,
            'ped_monto_devolucion' => $refund,
            'ped_devolucion_realizada' => $refund > 0 ? 'SI' : 'N/A',
        ]);
        DB::table('stj_pedidos_pago')->insert([
            'ppa_id' => $id,
            'ppa_pedido' => $id,
            'ppa_estado' => $paymentStatus,
            'ppa_tipo' => $paymentType,
            'ppa_monto_senv' => $amount,
            'ppa_fecha' => $date,
        ]);
    }
}
