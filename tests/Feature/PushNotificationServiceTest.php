<?php

namespace Tests\Feature;

use App\Services\PushNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
            npu_plataforma TEXT NULL,
            npu_promocion INTEGER NULL
        )');

        DB::statement('CREATE TABLE stj_tokens (
            tok_id INTEGER PRIMARY KEY AUTOINCREMENT,
            tok_token TEXT NULL,
            tok_session TEXT NULL,
            tok_tipo TEXT NULL,
            tok_fecha TEXT NULL,
            tok_topic TEXT NULL
        )');

        DB::statement('CREATE TABLE stj_promociones (
            prm_id INTEGER PRIMARY KEY AUTOINCREMENT,
            prm_nombre TEXT NULL
        )');

        Cache::flush();

        $serviceAccountPath = storage_path('framework/testing/firebase-service-account.json');

        if (! is_dir(dirname($serviceAccountPath))) {
            mkdir(dirname($serviceAccountPath), 0755, true);
        }

        file_put_contents($serviceAccountPath, json_encode([
            'client_email' => 'firebase-adminsdk@example.iam.gserviceaccount.com',
            'private_key' => $this->privateKey(),
        ], JSON_THROW_ON_ERROR));

        config()->set('services.fcm.url', 'https://fcm.test/v1');
        config()->set('services.fcm.project_id', 'stj-test');
        config()->set('services.fcm.service_account_json', $serviceAccountPath);
        config()->set('services.fcm.token_url', 'https://oauth2.test/token');
        config()->set('services.fcm.timeout', 10);
        config()->set('services.fcm.image_base_url', 'https://stjacks.com');
        config()->set('services.fcm.icon_url', 'https://cdn.test/logostj.png');
    }

    public function test_it_sends_pending_push_notifications(): void
    {
        Http::fake([
            'oauth2.test/*' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'fcm.test/*' => Http::response([
                'name' => 'projects/stj-test/messages/123',
            ], 200),
        ]);

        DB::table('stj_notificaciones_push')->insert([
            'npu_id' => 100,
            'npu_titulo' => 'Nueva promo',
            'npu_cuerpo' => 'Tenemos descuentos',
            'npu_imagen' => '/images/promo.jpg',
            'npu_action' => 'https://stjacks.com/promo',
            'npu_para' => '/topics/sv',
            'npu_plataforma' => 'WEB',
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
        $this->assertSame('{"name":"projects\/stj-test\/messages\/123"}', DB::table('stj_notificaciones_push_envios')->value('npe_resultado'));

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://oauth2.test/token') {
                return false;
            }

            $payload = $request->data();

            return $payload['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && filled($payload['assertion'] ?? null);
        });

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://fcm.test/v1/projects/stj-test/messages:send') {
                return false;
            }

            $payload = $request->data();
            $message = $payload['message'] ?? [];

            return $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $message['topic'] === 'sv'
                && $message['notification']['title'] === 'Nueva promo'
                && $message['notification']['body'] === 'Tenemos descuentos'
                && $message['notification']['image'] === 'https://stjacks.com/images/promo.jpg'
                && $message['data']['click_action'] === 'https://stjacks.com/promo'
                && $message['webpush']['notification']['icon'] === 'https://cdn.test/logostj.png'
                && $message['webpush']['fcm_options']['link'] === 'https://stjacks.com/promo';
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
            'npu_plataforma' => 'WEB',
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

    public function test_it_sends_pending_push_notifications_to_android_tokens(): void
    {
        Http::fake([
            'oauth2.test/*' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'fcm.test/*' => Http::response([
                'name' => 'projects/stj-test/messages/token',
            ], 200),
        ]);

        DB::table('stj_tokens')->insert([
            ['tok_token' => 'android-token-1', 'tok_tipo' => 'Android', 'tok_topic' => 'androidsv'],
            ['tok_token' => 'android-token-2', 'tok_tipo' => 'Android', 'tok_topic' => '/topics/androidsv'],
            ['tok_token' => 'ios-token-1', 'tok_tipo' => 'Ios', 'tok_topic' => 'androidsv'],
            ['tok_token' => '-', 'tok_tipo' => 'Android', 'tok_topic' => 'androidsv'],
        ]);

        DB::table('stj_notificaciones_push')->insert([
            'npu_id' => 101,
            'npu_titulo' => 'Android promo',
            'npu_cuerpo' => 'Solo Android',
            'npu_imagen' => '/images/promo.jpg',
            'npu_action' => 'https://stjacks.com/android',
            'npu_para' => '/topics/androidsv',
            'npu_plataforma' => 'Android',
            'npu_promocion' => null,
        ]);

        DB::table('stj_notificaciones_push_envios')->insert([
            'npe_id' => 201,
            'npe_notificacion' => 101,
            'npe_fecha_envio' => now()->subMinute()->toDateTimeString(),
            'npe_estado' => 'PENDIENTE',
            'npe_resultado' => null,
        ]);

        $summary = app(PushNotificationService::class)->sendPending();

        $this->assertSame(['pending' => 1, 'sent' => 1, 'failed' => 0], $summary);
        $this->assertSame('ENVIADO', DB::table('stj_notificaciones_push_envios')->value('npe_estado'));
        $this->assertStringContainsString('"platform":"Android"', DB::table('stj_notificaciones_push_envios')->value('npe_resultado'));
        $this->assertStringContainsString('"sent":2', DB::table('stj_notificaciones_push_envios')->value('npe_resultado'));

        $sentTokens = [];
        Http::assertSent(function ($request) use (&$sentTokens) {
            if ($request->url() !== 'https://fcm.test/v1/projects/stj-test/messages:send') {
                return false;
            }

            $sentTokens[] = $request->data()['message']['token'] ?? null;

            return true;
        });

        $this->assertSame(['android-token-1', 'android-token-2'], array_values(array_filter($sentTokens)));
    }

    public function test_it_uses_el_salvador_time_for_pending_push_notifications(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 09:55:00', 'America/El_Salvador'));

        try {
            Http::fake();

            DB::table('stj_notificaciones_push')->insert([
                'npu_id' => 102,
                'npu_titulo' => 'Promo tarde',
                'npu_cuerpo' => 'No enviar antes',
                'npu_imagen' => '/images/future.jpg',
                'npu_action' => 'https://stjacks.com/tarde',
                'npu_para' => '/topics/sv',
                'npu_plataforma' => 'WEB',
                'npu_promocion' => null,
            ]);

            DB::table('stj_notificaciones_push_envios')->insert([
                'npe_id' => 202,
                'npe_notificacion' => 102,
                'npe_fecha_envio' => '2026-06-23 15:00:00',
                'npe_estado' => 'PENDIENTE',
                'npe_resultado' => null,
            ]);

            $summary = app(PushNotificationService::class)->sendPending();

            $this->assertSame(['pending' => 0, 'sent' => 0, 'failed' => 0], $summary);
            $this->assertSame('PENDIENTE', DB::table('stj_notificaciones_push_envios')->value('npe_estado'));

            Http::assertNothingSent();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function privateKey(): string
    {
        return <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDGD7B+ps2nDrDP
0Gd52mckAsRC0Km1NPM+d1OWz0VVRNsuJBuN1GXKJrWcUk/avqGq09s+A9TE0kDK
xDbEa1PpmPLT22kOqlVJCMGqQ3AYN4Jw2ZFyjZr0rTTiwlR++GiQpntlaZEKcsxO
EpCPx+rmczj1hqdtk3EhiitDXZPjbUvc8Cq7EM0hnKnbvW8ja5pPjlYW6FR2A268
RV9N8E7dAloskpM6IluZ8djO+aYjDbYKeKtFNIjt3uJjX2NyuGIVRUec35xzVzg1
J+jQZln7Dm8RQhBjNMMRBbnwa2W1qlJSF81qgK4fIK7GQlwPKFvp4Z/S5GYEH+XZ
RMb4llCvAgMBAAECggEABIcWOxQ2+YgH0YzUfKh0tRnAAtsWtj3s6kB3JWLMwtEX
rG8tXk0UaPFZGJDrmj/+Jfp2GTd7c6o6fYGj5p0vJuKfyTRyOMMFugGoqYmG15Q3
UabQQ/fme+wGxLJMHy5V+t1oZsZKlgWwM8IE31mf6IO8K4pCj86BzvObGsjm2V2Z
6me//5MKDxNVV6eKg9FYbo7pLyFL2eD9h5V3uQWYKe1lJPc8VIh+FEwx9qOkoWD9
nqFZvhO6o0npqEzh/sXgzwBqEeAqeLjMjxmssgr1Do76W+8mwTg78NX27exgKpF8
13DyOOO1iSS6GuGxDfYoPmxsOBH/+47nqlXhR/p7YQKBgQDx8u3ePzB2pCg+cAEj
NyPmkD7S1aK2gSfdM1yPUeMfbSGlHcxUZ+MkK6xJrXgIzm19TovhYylffQ2MtBXR
YMpOBczCbUpSLQ3s0MHRkIW0+0xDVW2rPTWs2JYjCy7LTP48xGHyjGzVMWhVvUFK
uYdquvK0TH4G7EvSEU3lgYjChwKBgQDRnu5CijxvREczUpx50p+gmCvfvq6IuDJH
FWKT7WfCw1fwzUDEtg4WH6t9k6M0M8ey1HsmG7bYtIMeO/AeMDlH8OGEhcxk2bZX
JoX0rL5+QSUjwqsNcZla11gPlD14MS7SnI7UEhlsQmtfop0UoQNe+FUDbfXvsUlo
VlcD7HWuDwKBgH6gTxwdHfEJxjcIv4a3oCS93DW4PUF09b3BSoOjQlP4g9UkHltS
NNn8VwHFUj4TKVjYb45Gf3C4l9PIc4YLvFvweHTpRBp3HD9KZPcL5G14xIlTA5+0
3y4uQQxEBASkNg7m6R9PkbO/H/bqWuFo8I6o78kdTzWI98jtjn8zK31fAoGAFooQ
DoNwpJ9sk7sVfoMwzZg5u0rFD2HwkdT4L69u38gbX2Wfi82IfUxzDwhA9DVfuwBT
o2YtBRz1MBXbtYvhhUuy17pHi9YotxLBAXEh05qHFx8/JBOPukRfxdYVU+fSC8ng
bEZsDOZTW9wSGqKXItjOtxZNMJ9pbKtC59UuV+MCgYEAz4D1dRtndg4guSe4d8fx
RK4tB8JZ5gWQGB3tfWD8MrdHQziDzsSjiSOjNvL2SrGdExfXxxDgy4xkn9wXMPi2
6+i3cYuRmB6Z8ZzLnx5Wx02sx+nc4nx6opMoXb0VGvdm55fNVoXG69jUWcFdBAi5
9vudcoJQGUDjf7JxikSAFlE=
-----END PRIVATE KEY-----
KEY;
    }
}
