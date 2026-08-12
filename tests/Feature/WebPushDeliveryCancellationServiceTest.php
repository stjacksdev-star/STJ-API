<?php

namespace Tests\Feature;

use App\Services\WebPushDeliveryCancellationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebPushDeliveryCancellationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_push_entregas', function (Blueprint $table) {
            $table->bigInteger('pen_id')->primary();
            $table->string('pen_entidad_tipo');
            $table->bigInteger('pen_entidad_id');
            $table->unsignedInteger('pen_entidad_version')->nullable();
            $table->string('pen_estado');
            $table->text('pen_error')->nullable();
            $table->dateTime('pen_bloqueado_en')->nullable();
            $table->string('pen_bloqueado_por')->nullable();
            $table->dateTime('pen_actualizado_en');
        });
    }

    public function test_it_cancels_only_pending_stale_versions(): void
    {
        $this->seedDeliveries();
        $service = app(WebPushDeliveryCancellationService::class);

        $cancelled = $service->cancelStaleCartDeliveries(10, 4, 'El carrito cambio.');

        $this->assertSame(2, $cancelled);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 1, 'pen_estado' => 'CANCELADO', 'pen_error' => 'El carrito cambio.']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 2, 'pen_estado' => 'CANCELADO']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 3, 'pen_estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 4, 'pen_estado' => 'PROCESANDO']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 5, 'pen_estado' => 'ENVIADO']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 6, 'pen_estado' => 'PENDIENTE']);
    }

    public function test_it_cancels_all_pending_versions_when_the_cart_becomes_terminal(): void
    {
        $this->seedDeliveries();
        $service = app(WebPushDeliveryCancellationService::class);

        $cancelled = $service->cancelAllPendingCartDeliveries(10, 'El carrito fue convertido en pedido.');

        $this->assertSame(3, $cancelled);
        $this->assertSame(3, DB::table('stj_push_entregas')->where('pen_estado', 'CANCELADO')->count());
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 4, 'pen_estado' => 'PROCESANDO']);
        $this->assertDatabaseHas('stj_push_entregas', ['pen_id' => 5, 'pen_estado' => 'ENVIADO']);
    }

    private function seedDeliveries(): void
    {
        $now = now();
        DB::table('stj_push_entregas')->insert([
            ['pen_id' => 1, 'pen_entidad_tipo' => 'CART', 'pen_entidad_id' => 10, 'pen_entidad_version' => 3, 'pen_estado' => 'PENDIENTE', 'pen_actualizado_en' => $now],
            ['pen_id' => 2, 'pen_entidad_tipo' => 'CART', 'pen_entidad_id' => 10, 'pen_entidad_version' => null, 'pen_estado' => 'REINTENTO', 'pen_actualizado_en' => $now],
            ['pen_id' => 3, 'pen_entidad_tipo' => 'CART', 'pen_entidad_id' => 10, 'pen_entidad_version' => 4, 'pen_estado' => 'PENDIENTE', 'pen_actualizado_en' => $now],
            ['pen_id' => 4, 'pen_entidad_tipo' => 'CART', 'pen_entidad_id' => 10, 'pen_entidad_version' => 3, 'pen_estado' => 'PROCESANDO', 'pen_actualizado_en' => $now],
            ['pen_id' => 5, 'pen_entidad_tipo' => 'CART', 'pen_entidad_id' => 10, 'pen_entidad_version' => 3, 'pen_estado' => 'ENVIADO', 'pen_actualizado_en' => $now],
            ['pen_id' => 6, 'pen_entidad_tipo' => 'CART', 'pen_entidad_id' => 11, 'pen_entidad_version' => 3, 'pen_estado' => 'PENDIENTE', 'pen_actualizado_en' => $now],
        ]);
    }
}
