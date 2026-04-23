<?php

namespace Tests\Feature;

use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class Smtp2GoMailerTest extends TestCase
{
    public function test_it_sends_html_email_with_smtp2go_payload(): void
    {
        config()->set('services.smtp2go.url', 'https://api.smtp2go.test/v3/email/send');
        config()->set('services.smtp2go.key', 'test-api-key');
        config()->set('services.smtp2go.sender', '"St. Jack\'s" Online <no-reply@stjacks.online>');

        Http::fake([
            'api.smtp2go.test/*' => Http::response([
                'data' => [
                    'succeeded' => 1,
                    'failed' => 0,
                    'failures' => [],
                ],
            ]),
        ]);

        $result = app(Smtp2GoMailer::class)->sendHtml(
            to: ['Cliente' => 'cliente@example.com'],
            subject: 'Pedido preparado',
            html: '<h1>Tu pedido esta listo</h1>',
            cc: ['cc@example.com'],
            bcc: 'audit@example.com',
            attachments: [[
                'filename' => 'recibo.txt',
                'fileblob' => base64_encode('recibo'),
                'mimetype' => 'text/plain',
            ]],
            inlines: [[
                'filename' => 'logo.png',
                'fileblob' => base64_encode('logo'),
                'mimetype' => 'image/png',
            ]],
        );

        $this->assertSame(1, data_get($result, 'data.succeeded'));

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://api.smtp2go.test/v3/email/send'
                && $payload['api_key'] === 'test-api-key'
                && $payload['sender'] === '"St. Jack\'s" Online <no-reply@stjacks.online>'
                && $payload['subject'] === 'Pedido preparado'
                && $payload['to'] === ['"Cliente" <cliente@example.com>']
                && $payload['cc'] === ['cc@example.com']
                && $payload['bcc'] === ['audit@example.com']
                && $payload['html_body'] === '<h1>Tu pedido esta listo</h1>'
                && $payload['attachments'][0]['filename'] === 'recibo.txt'
                && $payload['inlines'][0]['filename'] === 'logo.png';
        });
    }

    public function test_it_requires_api_key(): void
    {
        config()->set('services.smtp2go.key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP2GO_API_KEY no esta configurado.');

        app(Smtp2GoMailer::class)->sendHtml('cliente@example.com', 'Asunto', '<p>Contenido</p>');
    }
}
