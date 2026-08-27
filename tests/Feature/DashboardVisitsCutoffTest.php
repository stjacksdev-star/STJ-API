<?php

namespace Tests\Feature;

use App\Services\Dashboard\SalesKpiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardVisitsCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id', true);
            $table->string('pai_codigo', 3);
            $table->string('pai_nombre', 80);
        });
        Schema::create('stj_visitas', function (Blueprint $table) {
            $table->dateTime('vis_fecha');
            $table->string('vis_pais')->nullable();
            $table->string('vis_plataforma');
        });
        Schema::create('stj_visitas_diarias', function (Blueprint $table) {
            $table->bigInteger('vdi_id', true);
            $table->date('vdi_fecha');
            $table->bigInteger('vdi_visitante_id');
            $table->bigInteger('vdi_usuario_id')->nullable();
            $table->bigInteger('vdi_pais_id');
            $table->string('vdi_origen', 20);
            $table->dateTime('vdi_primera_hora');
            $table->dateTime('vdi_ultima_hora');
            $table->dateTime('vdi_creado_en');
            $table->dateTime('vdi_actualizado_en');
        });

        DB::table('stj_paises')->insert([
            'pai_id' => 1,
            'pai_codigo' => 'SV',
            'pai_nombre' => 'El Salvador',
        ]);
    }

    public function test_cutoff_uses_legacy_before_date_and_daily_visits_from_date_inclusive(): void
    {
        config()->set('analytics.daily_visits_cutoff_date', '2026-08-30');
        $this->legacyVisit('2026-08-29 08:00:00');
        $this->legacyVisit('2026-08-29 09:00:00');
        $this->legacyVisit('2026-08-30 08:00:00'); // Must be excluded after cutover.
        $this->dailyVisit('2026-08-29', 'WEB'); // Must be excluded before cutover.
        $this->dailyVisit('2026-08-30', 'WEB');
        $this->dailyVisit('2026-08-30', 'WEB');
        $this->dailyVisit('2026-08-31', 'APP-IOS');

        $general = app(SalesKpiService::class)->visitsChart('2026-08-29', '2026-08-31', 'general');
        $series = collect($general['series'])->keyBy('key');

        $this->assertSame([2, 2, 0], $series['visits_web']['data']);
        $this->assertSame([0, 0, 1], $series['visits_ios']['data']);
        $this->assertSame([
            ['country' => 'ElSalvador', 'visits' => 5],
        ], $general['rows']);

        $country = app(SalesKpiService::class)->visitsChart(
            '2026-08-29',
            '2026-08-31',
            'sv',
            '2026-08-29',
            '2026-08-31',
        );

        $this->assertSame([2, 2, 1], $country['series'][0]['data']);
        $this->assertSame(5, $country['totals']['visits']);
    }

    public function test_empty_cutoff_keeps_report_entirely_on_legacy_table(): void
    {
        config()->set('analytics.daily_visits_cutoff_date', null);
        $this->legacyVisit('2026-08-30 08:00:00');
        $this->dailyVisit('2026-08-30', 'WEB');

        $result = app(SalesKpiService::class)->visitsChart('2026-08-30', '2026-08-30', 'general');
        $web = collect($result['series'])->firstWhere('key', 'visits_web');

        $this->assertSame([1], $web['data']);
        $this->assertSame(1, $result['rows'][0]['visits']);
    }

    private function legacyVisit(string $date, string $platform = 'WEB'): void
    {
        DB::table('stj_visitas')->insert([
            'vis_fecha' => $date,
            'vis_pais' => 'ElSalvador',
            'vis_plataforma' => $platform,
        ]);
    }

    private function dailyVisit(string $date, string $origin): void
    {
        static $visitor = 0;
        $visitor++;

        DB::table('stj_visitas_diarias')->insert([
            'vdi_fecha' => $date,
            'vdi_visitante_id' => $visitor,
            'vdi_usuario_id' => null,
            'vdi_pais_id' => 1,
            'vdi_origen' => $origin,
            'vdi_primera_hora' => $date.' 08:00:00',
            'vdi_ultima_hora' => $date.' 08:00:00',
            'vdi_creado_en' => $date.' 08:00:00',
            'vdi_actualizado_en' => $date.' 08:00:00',
        ]);
    }
}
