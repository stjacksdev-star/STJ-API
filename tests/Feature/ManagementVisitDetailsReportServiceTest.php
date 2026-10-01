<?php

namespace Tests\Feature;

use App\Services\Dashboard\ManagementVisitDetailsReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManagementVisitDetailsReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('analytics.daily_visits_cutoff_date', '2026-08-30');

        Schema::create('stj_paises', function (Blueprint $table): void {
            $table->id('pai_id');
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });
        Schema::create('stj_visitas', function (Blueprint $table): void {
            $table->dateTime('vis_fecha');
            $table->string('vis_pais')->nullable();
            $table->string('vis_plataforma');
        });
        Schema::create('stj_visitas_diarias', function (Blueprint $table): void {
            $table->id('vdi_id');
            $table->date('vdi_fecha');
            $table->unsignedBigInteger('vdi_pais_id');
            $table->string('vdi_origen');
        });
        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador'],
            ['pai_id' => 2, 'pai_codigo' => 'GT', 'pai_nombre' => 'Guatemala'],
        ]);
        DB::table('stj_visitas')->insert([
            ['vis_fecha' => '2026-08-29 08:00:00', 'vis_pais' => 'ElSalvador', 'vis_plataforma' => 'WEB'],
            ['vis_fecha' => '2026-08-29 09:00:00', 'vis_pais' => 'Guatemala', 'vis_plataforma' => 'APP-IOS'],
            ['vis_fecha' => '2026-08-30 08:00:00', 'vis_pais' => 'ElSalvador', 'vis_plataforma' => 'WEB'],
        ]);
        DB::table('stj_visitas_diarias')->insert([
            ['vdi_fecha' => '2026-08-29', 'vdi_pais_id' => 1, 'vdi_origen' => 'WEB'],
            ['vdi_fecha' => '2026-08-30', 'vdi_pais_id' => 1, 'vdi_origen' => 'APP-ANDROID'],
            ['vdi_fecha' => '2026-08-30', 'vdi_pais_id' => 2, 'vdi_origen' => 'WEB'],
            ['vdi_fecha' => '2026-08-31', 'vdi_pais_id' => 1, 'vdi_origen' => 'APP-IOS'],
        ]);
    }

    public function test_report_breaks_visits_down_by_date_country_and_platform_without_double_counting_cutoff(): void
    {
        $report = app(ManagementVisitDetailsReportService::class)->report('2026-08-29', '2026-08-31');

        $this->assertSame(5, $report['summary']['visits']);
        $this->assertCount(5, $report['rows']);
        $this->assertSame(['2026-08-29', '2026-08-30', '2026-08-31'], $report['chart']['categories']);
        $this->assertSame([1, 1, 0], collect($report['chart']['series'])->firstWhere('key', 'WEB')['data']);
        $this->assertSame([0, 1, 0], collect($report['chart']['series'])->firstWhere('key', 'APP-ANDROID')['data']);
    }

    public function test_country_and_platform_filters_apply_to_table_chart_and_total(): void
    {
        $report = app(ManagementVisitDetailsReportService::class)->report('2026-08-29', '2026-08-31', 'SV', 'APP-IOS');

        $this->assertSame(1, $report['summary']['visits']);
        $this->assertSame('SV', $report['rows'][0]['countryCode']);
        $this->assertSame('APP-IOS', $report['rows'][0]['platform']);
        $this->assertSame([0, 0, 1], $report['chart']['series'][0]['data']);
    }
}
