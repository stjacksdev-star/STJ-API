<?php

namespace Tests\Feature;

use App\Services\PromotionLifecycleNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PromotionLifecycleNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table) {
            $table->id('pai_id');
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });
        Schema::create('stj_promociones', function (Blueprint $table) {
            $table->id('prm_id');
            $table->unsignedBigInteger('prm_pais');
            $table->string('prm_nombre');
        });
        Schema::create('stj_promociones_producto', function (Blueprint $table) {
            $table->id('ppr_id');
            $table->unsignedBigInteger('ppr_promocion');
            $table->unsignedBigInteger('ppr_producto');
        });

        DB::table('stj_paises')->insert(['pai_id' => 1, 'pai_codigo' => 'SV', 'pai_nombre' => 'El Salvador']);
        DB::table('stj_promociones')->insert(['prm_id' => 10, 'prm_pais' => 1, 'prm_nombre' => 'Regreso a clases']);
        DB::table('stj_promociones_producto')->insert([
            ['ppr_promocion' => 10, 'ppr_producto' => 100],
            ['ppr_promocion' => 10, 'ppr_producto' => 101],
        ]);

        config()->set('promotions.notification_recipients', [' operaciones@stjacks.com ', 'operaciones@stjacks.com', 'invalido']);
        config()->set('services.smtp2go.key', 'test-key');
        config()->set('services.smtp2go.sender', 'no-reply@example.test');
        Http::fake(['*' => Http::response(['data' => ['failed' => 0]], 200)]);
    }

    public function test_it_sends_a_simple_country_and_product_activation_notice(): void
    {
        $summary = app(PromotionLifecycleNotificationService::class)->send([
            ['promotionId' => 10, 'type' => 'activation', 'from' => 'PENDIENTE', 'to' => 'EN-PROCESO'],
        ]);

        $this->assertSame(1, $summary['sent']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertSame([], $summary['errors']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $payload['to'] === ['operaciones@stjacks.com']
                && str_contains($payload['subject'], 'Regreso a clases activada - El Salvador')
                && str_contains($payload['html_body'], '2 productos activados de la promoción &quot;Regreso a clases&quot; en El Salvador.');
        });
    }

    public function test_an_empty_recipient_list_disables_notifications(): void
    {
        config()->set('promotions.notification_recipients', []);

        $summary = app(PromotionLifecycleNotificationService::class)->send([
            ['promotionId' => 10, 'type' => 'activation'],
        ]);

        $this->assertSame(['sent' => 0, 'skipped' => 1, 'errors' => []], $summary);
        Http::assertNothingSent();
    }
}
