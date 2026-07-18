<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\StorefrontCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontProductViewEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->bigInteger('pai_id', true);
            $table->string('pai_codigo', 3);
        });
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->bigInteger('pro_id', true);
        });
        Schema::create('stj_usuarios', function (Blueprint $table) {
            $table->bigInteger('usu_id', true);
        });
        Schema::create('stj_pedidos', function (Blueprint $table) {
            $table->bigInteger('ped_id', true);
        });
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->bigInteger('prm_id', true);
        });

        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV']);
        DB::table('stj_productos')->insert(['pro_id' => 10]);
    }

    public function test_guest_product_view_is_idempotent_and_sets_http_only_cookie(): void
    {
        $uuid = (string) Str::uuid();
        $payload = $this->payload($uuid);

        $first = $this->postJson('/api/storefront/events', $payload);
        $cookie = $first->getCookie('stj_visitor', false);

        $first->assertCreated()->assertJsonPath('data.created', true);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));

        $second = $this->withUnencryptedCookie('stj_visitor', $cookie->getValue())
            ->postJson('/api/storefront/events', $payload);

        $second->assertOk()->assertJsonPath('data.created', false);
        $this->assertDatabaseCount('stj_cliente_eventos', 1);
        $this->assertDatabaseHas('stj_cliente_eventos', [
            'cev_event_uuid' => $uuid,
            'cev_usu_id' => null,
            'cev_tipo' => 'PRODUCT_VIEW',
        ]);
    }

    public function test_technical_bearer_is_not_attributed_as_customer(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/storefront/events', $this->payload((string) Str::uuid()))
            ->assertCreated();

        $this->assertDatabaseHas('stj_cliente_eventos', ['cev_usu_id' => null]);
    }

    public function test_storefront_customer_is_attributed_from_sanctum(): void
    {
        DB::table('stj_usuarios')->insert(['usu_id' => 77]);
        $customer = StorefrontCustomer::query()->findOrFail(77);

        Sanctum::actingAs($customer, ['storefront:account']);
        $this->postJson('/api/storefront/events', $this->payload((string) Str::uuid()))
            ->assertCreated();

        $this->assertDatabaseHas('stj_cliente_eventos', [
            'cev_usu_id' => 77,
        ]);
    }

    private function payload(string $uuid): array
    {
        return [
            'event_uuid' => $uuid,
            'type' => 'PRODUCT_VIEW',
            'country' => 'sv',
            'product_id' => 10,
            'occurred_at' => now()->subSecond()->toISOString(),
            'metadata' => ['slug' => 'producto-10', 'sku' => 'SKU-10'],
        ];
    }
}
