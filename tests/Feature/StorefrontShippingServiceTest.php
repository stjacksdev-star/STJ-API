<?php

namespace Tests\Feature;

use App\Services\StorefrontShippingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StorefrontShippingServiceTest extends TestCase
{
    use RefreshDatabase;

    private StorefrontShippingService $shipping;
    private object $sv;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('stj_world_states', function (Blueprint $t) { $t->bigInteger('id'); $t->bigInteger('country_id'); $t->string('name'); });
        Schema::create('stj_world_cities', function (Blueprint $t) { $t->bigInteger('id'); $t->bigInteger('state_id'); $t->bigInteger('country_id'); $t->string('name'); $t->decimal('costo', 10, 2)->default(0); $t->string('envio_disponible')->nullable(); $t->integer('id_urbano')->nullable(); });
        Schema::create('stj_envio_pais', function (Blueprint $t) { $t->increments('envio_id'); $t->bigInteger('envio_pais'); $t->decimal('envio_valor', 10, 2); $t->string('envio_estado'); $t->string('envio_moneda_simbolo'); });
        Schema::create('stj_envio_reglas', function (Blueprint $t) { $t->increments('enr_id'); $t->string('enr_nombre'); $t->bigInteger('enr_pais'); $t->string('enr_tipo'); $t->decimal('enr_monto_minimo', 10, 2); $t->decimal('enr_valor_envio', 10, 2); $t->dateTime('enr_fecha_inicio'); $t->dateTime('enr_fecha_fin'); $t->string('enr_estado'); $t->integer('enr_prioridad'); });
        DB::table('stj_world_states')->insert([['id' => 10, 'country_id' => 100, 'name' => 'SV State'], ['id' => 20, 'country_id' => 200, 'name' => 'HN State']]);
        DB::table('stj_world_cities')->insert([['id' => 11, 'state_id' => 10, 'country_id' => 100, 'name' => 'Santa Tecla', 'costo' => 0], ['id' => 21, 'state_id' => 20, 'country_id' => 200, 'name' => 'San Pedro Sula', 'costo' => 125]]);
        DB::table('stj_envio_pais')->insert([['envio_pais' => 1, 'envio_valor' => 2.50, 'envio_estado' => 'ACTIVO', 'envio_moneda_simbolo' => '$'], ['envio_pais' => 7, 'envio_valor' => 95, 'envio_estado' => 'ACTIVO', 'envio_moneda_simbolo' => 'L']]);
        $this->shipping = new StorefrontShippingService;
        $this->sv = (object) ['pai_id' => 1, 'pai_id_world' => 100, 'pai_codigo' => 'SV'];
    }

    public function test_store_pickup_is_always_zero(): void
    {
        $quote = $this->shipping->quote($this->sv, 'TIENDA', null, '10.00');
        $this->assertSame('0.00', $quote['shipping_amount']);
        $this->assertSame('STORE_PICKUP', $quote['source']);
    }

    public function test_active_free_rule_has_priority_and_reports_threshold(): void
    {
        $this->freeRule();
        $pending = $this->shipping->quote($this->sv, 'DOMICILIO', 11, '20.00');
        $free = $this->shipping->quote($this->sv, 'DOMICILIO', 11, '30.00');
        $this->assertSame('10.00', $pending['remaining_for_free_shipping']);
        $this->assertStringContainsString('Agrega $10.00', $pending['message']);
        $this->assertSame('0.00', $free['shipping_amount']);
        $this->assertSame('FREE_RULE', $free['source']);
    }

    public function test_city_rate_overrides_country_and_country_is_fallback(): void
    {
        $hn = (object) ['pai_id' => 7, 'pai_id_world' => 200, 'pai_codigo' => 'HN'];
        $this->assertSame('125.00', $this->shipping->quote($hn, 'DOMICILIO', 21, '10.00')['shipping_amount']);
        $this->assertSame('CITY_RATE', $this->shipping->quote($hn, 'DOMICILIO', 21, '10.00')['source']);
        DB::table('stj_world_cities')->where('id', 11)->update(['costo' => 50]);
        $this->assertSame('2.50', $this->shipping->quote($this->sv, 'DOMICILIO', 11, '10.00')['shipping_amount']);
        $this->assertSame('COUNTRY_RATE', $this->shipping->quote($this->sv, 'DOMICILIO', 11, '10.00')['source']);
        config(['storefront_shipping.city_rate_countries' => ['HN', 'SV']]);
        $this->assertSame('50.00', $this->shipping->quote($this->sv, 'DOMICILIO', 11, '10.00')['shipping_amount']);
    }

    public function test_invalid_city_and_missing_configuration_are_errors(): void
    {
        try { $this->shipping->quote($this->sv, 'DOMICILIO', 999, '10.00'); $this->fail('Expected invalid city.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('delivery.city_id', $e->errors()); }
        DB::table('stj_envio_pais')->where('envio_pais', 1)->delete();
        $this->expectException(ValidationException::class);
        $this->shipping->quote($this->sv, 'DOMICILIO', 11, '10.00');
    }

    private function freeRule(): void
    {
        DB::table('stj_envio_reglas')->insert(['enr_nombre' => 'Gratis SV', 'enr_pais' => 1, 'enr_tipo' => 'ENVIO_GRATIS', 'enr_monto_minimo' => 30, 'enr_valor_envio' => 0, 'enr_fecha_inicio' => now()->subDay(), 'enr_fecha_fin' => now()->addDay(), 'enr_estado' => 'ACTIVO', 'enr_prioridad' => 1]);
    }
}
