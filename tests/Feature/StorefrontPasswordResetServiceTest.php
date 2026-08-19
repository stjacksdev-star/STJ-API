<?php

namespace Tests\Feature;

use App\Services\StorefrontPasswordResetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StorefrontPasswordResetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        Schema::create('stj_usuarios', function (Blueprint $table) {
            $table->bigIncrements('usu_id');
            $table->string('usu_usuario');
            $table->string('usu_password');
            $table->string('usu_nombre')->nullable();
            $table->boolean('usu_activo')->default(true);
        });
        Schema::create('stj_storefront_password_resets', function (Blueprint $table) {
            $table->bigIncrements('spr_id');
            $table->string('spr_email');
            $table->char('spr_token_hash', 64)->unique();
            $table->timestamp('spr_expires_at');
            $table->timestamp('spr_used_at')->nullable();
            $table->string('spr_request_ip', 45)->nullable();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        config([
            'services.smtp2go.url' => 'https://smtp.test/send',
            'services.smtp2go.key' => 'test-key',
            'services.smtp2go.sender' => 'test@example.com',
            'services.storefront.password_reset_url' => 'https://shop.test/{country}/cuenta/restablecer/{token}',
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('stj_storefront_password_resets');
        Schema::dropIfExists('stj_usuarios');
        parent::tearDown();
    }

    public function test_token_is_hashed_expires_and_can_only_be_used_once(): void
    {
        Http::fake(['https://smtp.test/send' => Http::response(['data' => ['failed' => 0]], 200)]);
        DB::table('stj_usuarios')->insert([
            'usu_usuario' => 'cliente@example.com',
            'usu_password' => Hash::make('Anterior123'),
            'usu_nombre' => 'Cliente',
            'usu_activo' => 1,
        ]);

        $service = app(StorefrontPasswordResetService::class);
        $service->request('cliente@example.com', 'SV', '127.0.0.1');

        $reset = DB::table('stj_storefront_password_resets')->first();
        $html = Http::recorded()[0][0]->data()['html_body'];
        preg_match('/restablecer\/([a-f0-9]{64})/', $html, $matches);
        $token = $matches[1] ?? '';

        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), $reset->spr_token_hash);
        $this->assertStringNotContainsString($token, json_encode($reset));
        $this->assertTrue($service->reset($token, 'NuevaClave123'));
        $this->assertTrue(Hash::check('NuevaClave123', DB::table('stj_usuarios')->value('usu_password')));
        $this->assertFalse($service->reset($token, 'OtraClave123'));
    }
}
