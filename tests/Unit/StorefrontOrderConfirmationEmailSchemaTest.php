<?php

namespace Tests\Unit;

use App\Services\Mail\Smtp2GoMailer;
use App\Services\Mail\StorefrontMailTemplate;
use App\Services\StorefrontOrderConfirmationEmailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontOrderConfirmationEmailSchemaTest extends TestCase
{
    public function test_order_can_be_read_before_pickup_document_type_migration(): void
    {
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->integer('ped_id'); $table->integer('ped_id_pais'); $table->string('ped_tienda');
        });
        Schema::create('stj_pedidos_pago', function (Blueprint $table) {
            $table->integer('ppa_id'); $table->integer('ppa_pedido'); $table->string('ppa_ref');
        });
        Schema::create('stj_paises', function (Blueprint $table) {
            $table->integer('pai_id'); $table->integer('pai_id_world'); $table->string('pai_codigo'); $table->string('pai_nombre');
        });
        Schema::create('stj_tiendas', function (Blueprint $table) {
            $table->integer('tie_pais'); $table->string('tie_codigo'); $table->string('tie_nombre'); $table->string('tie_correo')->nullable();
        });
        Schema::create('stj_pedidos_tienda', function (Blueprint $table) {
            $table->integer('pti_pedido'); $table->string('pti_misma_persona'); $table->string('pti_persona'); $table->string('pti_telefono'); $table->string('pti_identificacion');
        });
        Schema::create('stj_world_countries', function (Blueprint $table) {
            $table->integer('id'); $table->string('phonecode');
        });
        Schema::create('stj_pedidos_direccion', function (Blueprint $table) {
            $table->integer('pdi_pedido'); $table->integer('pdi_direccion'); $table->string('pdi_costo_envio_txt');
        });
        Schema::create('stj_direcciones', function (Blueprint $table) {
            $table->integer('dir_id'); $table->string('dir_direccion'); $table->string('dir_referencia'); $table->string('dir_departamento_txt'); $table->string('dir_municipio_txt');
        });
        DB::table('stj_pedidos')->insert(['ped_id' => 1, 'ped_id_pais' => 1, 'ped_tienda' => '024']);
        DB::table('stj_pedidos_pago')->insert(['ppa_id' => 2, 'ppa_pedido' => 1, 'ppa_ref' => 'STJ123']);
        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_id_world' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador']);

        $service = new StorefrontOrderConfirmationEmailService(new Smtp2GoMailer, new StorefrontMailTemplate);
        $order = (new \ReflectionMethod($service, 'order'))->invoke($service, 1, 2);

        $this->assertNotNull($order);
        $this->assertNull($order->pti_tipo_identificacion);
    }
}
