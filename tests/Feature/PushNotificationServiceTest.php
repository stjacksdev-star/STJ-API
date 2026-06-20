<?php

namespace Tests\Feature;

use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE stj_notificaciones_push_envios (
            npe_id INTEGER PRIMARY KEY AUTOINCREMENT,
            npe_notificacion INTEGER NOT NULL,
            npe_fecha_envio TEXT NOT NULL,
            npe_estado TEXT NOT NULL,
            npe_resultado TEXT NULL
        )');

        DB::statement('CREATE TABLE stj_notificaciones_push (
            npu_id INTEGER PRIMARY KEY AUTOINCREMENT,
            npu_titulo TEXT NOT NULL,
            npu_cuerpo TEXT NOT NULL,
            npu_imagen TEXT NOT NULL,
            npu_action TEXT NOT NULL,
            npu_para TEXT NOT NULL,
            npu_promocion INTEGER NULL
        )');

        DB::statement('CREATE TABLE stj_promociones (
            prm_id INTEGER PRIMARY KEY AUTOINCREMENT,
            prm_nombre TEXT NULL
        )');

        config()->set('services.fcm.url', 'https://fcm.test/fcm/send');
        config()->set('services.fcm.server_key', 'test-server-key');
        config()->set('services.fcm.timeout', 10);
        config()->set('services.fcm.image_base_url', 'https://stjacks.com');
        config()->set('services.fcm.icon_url', 'https://cdn.test/logostj.png');
    }

    public function test_it_sends_pending_push_notifications(): void
    {
        Http::fake([
            'fcm.test/*' => Http::response('{"success":1}', 200),
        ]);

        DB::table('stj_notificaciones_push')->insert([
            'npu_id' => 100,
            'npu_titulo' => 'Nueva promo',
            'npu_cuerpo' => 'Tenemos descuentos',
            'npu_imagen' => '/images/promo.jpg',
            'npu_action' => 'https://stjacks.com/promo',
            'npu_para' => '/topics/sv',
            'npu_promocion' => null,
        ]);

        DB::table('stj_notificaciones_push_envios')->insert([
            'npe_id' => 200,
            'npe_notificacion' => 100,
            'npe_fecha_envio' => now()->subMinute()->toDateTimeString(),
            'npe_estado' => 'PENDIENTE',
            'npe_resultado' => null,
        ]);

        $summary = app(PushNotificationService::class)->sendPending();

        $this->assertSame(['pending' => 1, 'sent' => 1, 'failed' => 0], $summary);
        $this->assertSame('ENVIADO', DB::table('stj_notificaciones_push_envios')->value('npe_estado'));
        $this->assertSame(serialize('{"success":1}'), DB::table('stj_notificaciones_push_envios')->value('npe_resultado'));

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://fcm.test/fcm/send'
                && $request->hasHeader('Authorization', 'key=test-server-key')
                && $payload['to'] === '/topics/sv'
                && $payload['notification']['title'] === 'Nueva promo'
                && $payload['notification']['body'] === 'Tenemos descuentos'
                && $payload['notification']['image'] === 'https://stjacks.com/images/promo.jpg'
                && $payload['notification']['icon'] === 'https://cdn.test/logostj.png'
                && $payload['notification']['click_action'] === 'https://stjacks.com/promo';
        });
    }

    public function test_it_ignores_future_notifications(): void
    {
        Http::fake();

        DB::table('stj_notificaciones_push')->insert([
            'npu_id' => 100,
            'npu_titulo' => 'Futura',
            'npu_cuerpo' => 'Todavia no',
            'npu_imagen' => '/images/future.jpg',
            'npu_action' => 'app://future',
            'npu_para' => '/topics/sv',
            'npu_promocion' => null,
        ]);

        DB::table('stj_notificaciones_push_envios')->insert([
            'npe_id' => 200,
            'npe_notificacion' => 100,
            'npe_fecha_envio' => now()->addHour()->toDateTimeString(),
            'npe_estado' => 'PENDIENTE',
            'npe_resultado' => null,
        ]);

        $summary = app(PushNotificationService::class)->sendPending();

        $this->assertSame(['pending' => 0, 'sent' => 0, 'failed' => 0], $summary);
        $this->assertSame('PENDIENTE', DB::table('stj_notificaciones_push_envios')->value('npe_estado'));

        Http::assertNothingSent();
    }
}
