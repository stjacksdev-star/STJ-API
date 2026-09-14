<?php

namespace Tests\Feature;

use App\Services\Dashboard\SalesKpiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardAppInstallationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id'); $table->string('pai_codigo'); $table->string('pai_nombre');
        });
        Schema::create('stj_visitantes', function (Blueprint $table) {
            $table->id('vis_id'); $table->uuid('vis_uuid'); $table->string('vis_origen');
            $table->unsignedBigInteger('vis_pais_id')->nullable(); $table->dateTime('vis_primera_visita');
        });
        DB::table('stj_paises')->insert([
            ['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador'],
            ['pai_id' => 2, 'pai_codigo' => 'GT', 'pai_nombre' => 'Guatemala'],
        ]);
        DB::table('stj_visitantes')->insert([
            ['vis_id' => 1, 'vis_uuid' => '00000000-0000-4000-8000-000000000001', 'vis_origen' => 'APP-ANDROID', 'vis_pais_id' => 1, 'vis_primera_visita' => '2026-01-10 10:00:00'],
            ['vis_id' => 2, 'vis_uuid' => '00000000-0000-4000-8000-000000000002', 'vis_origen' => 'APP-IOS', 'vis_pais_id' => 1, 'vis_primera_visita' => '2026-01-11 10:00:00'],
            ['vis_id' => 3, 'vis_uuid' => '00000000-0000-4000-8000-000000000003', 'vis_origen' => 'APP-ANDROID', 'vis_pais_id' => 2, 'vis_primera_visita' => '2026-01-12 10:00:00'],
            ['vis_id' => 4, 'vis_uuid' => '00000000-0000-4000-8000-000000000004', 'vis_origen' => 'WEB', 'vis_pais_id' => 1, 'vis_primera_visita' => '2026-01-13 10:00:00'],
        ]);
    }

    public function test_it_counts_first_mobile_installations_for_the_selected_country(): void
    {
        $result = app(SalesKpiService::class)->appInstallations(2026, 1);

        $this->assertSame('El Salvador', $result['filters']['countryName']);
        $this->assertSame(1, $result['summary']['android']);
        $this->assertSame(1, $result['summary']['ios']);
        $this->assertSame(2, $result['summary']['total']);
        $this->assertSame([2026], $result['years']);
    }

    public function test_it_groups_custom_range_installations_by_monday_to_sunday_weeks(): void
    {
        $result = app(SalesKpiService::class)->appInstallations(2026, 1, '2026-01-05', '2026-01-18');

        $this->assertSame('2026-01-05', $result['range']['filters']['startDate']);
        $this->assertSame('2026-01-18', $result['range']['filters']['endDate']);
        $this->assertCount(2, $result['range']['rows']);
        $this->assertSame(2, $result['range']['summary']['total']);
        $this->assertSame(1, $result['range']['rows'][0]['android']);
        $this->assertSame(1, $result['range']['rows'][0]['ios']);
        $this->assertSame(0, $result['range']['rows'][1]['android']);
        $this->assertSame(0, $result['range']['rows'][1]['ios']);
    }
}
