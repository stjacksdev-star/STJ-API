<?php

namespace Tests\Feature;

use App\Services\VipCustomerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VipCustomerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-29 12:00:00');
        Schema::create('stj_usuarios', function (Blueprint $table) {
            $table->id('usu_id');
            $table->string('usu_vip')->default('NO');
        });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->id('ped_id');
            $table->unsignedBigInteger('ped_user')->nullable();
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->id('ppa_id');
            $table->unsignedBigInteger('ppa_pedido');
            $table->string('ppa_estado');
            $table->dateTime('ppa_fecha');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_requires_three_approved_purchases_during_the_last_six_months(): void
    {
        DB::table('stj_usuarios')->insert([
            ['usu_id' => 1, 'usu_vip' => 'NO'],
            ['usu_id' => 2, 'usu_vip' => 'SI'],
            ['usu_id' => 3, 'usu_vip' => 'SI'],
        ]);
        DB::table('stj_pedidos')->insert([
            ['ped_id' => 10, 'ped_user' => 1],
            ['ped_id' => 11, 'ped_user' => 1],
            ['ped_id' => 12, 'ped_user' => 1],
            ['ped_id' => 20, 'ped_user' => 2],
            ['ped_id' => 21, 'ped_user' => 2],
            ['ped_id' => 30, 'ped_user' => 3],
        ]);
        DB::table('stj_pedidos_pago')->insert([
            ['ppa_id' => 100, 'ppa_pedido' => 10, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2026-08-01 10:00:00'],
            ['ppa_id' => 101, 'ppa_pedido' => 11, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2026-07-01 10:00:00'],
            ['ppa_id' => 102, 'ppa_pedido' => 12, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2026-06-01 10:00:00'],
            ['ppa_id' => 200, 'ppa_pedido' => 20, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2026-01-01 10:00:00'],
            ['ppa_id' => 201, 'ppa_pedido' => 21, 'ppa_estado' => 'APROBADA', 'ppa_fecha' => '2026-08-01 10:00:00'],
            ['ppa_id' => 300, 'ppa_pedido' => 30, 'ppa_estado' => 'DENEGADA', 'ppa_fecha' => '2026-08-01 10:00:00'],
        ]);

        $summary = app(VipCustomerService::class)->refresh();

        $this->assertSame(1, $summary['qualified']);
        $this->assertDatabaseHas('stj_usuarios', ['usu_id' => 1, 'usu_vip' => 'SI']);
        $this->assertDatabaseHas('stj_usuarios', ['usu_id' => 2, 'usu_vip' => 'NO']);
        $this->assertDatabaseHas('stj_usuarios', ['usu_id' => 3, 'usu_vip' => 'NO']);
    }
}
