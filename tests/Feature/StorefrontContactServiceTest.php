<?php

namespace Tests\Feature;

use App\Services\StorefrontContactService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StorefrontContactServiceTest extends TestCase
{
    public function test_it_sends_escaped_contact_content_through_smtp2go(): void
    {
        config([
            'services.smtp2go.url' => 'https://smtp.test/send',
            'services.smtp2go.key' => 'test-key',
            'services.smtp2go.sender' => 'web@example.com',
            'services.storefront.contact_to' => 'servicio@example.com',
        ]);
        Http::fake(['https://smtp.test/send' => Http::response(['data' => ['failed' => 0]], 200)]);

        $reference = app(StorefrontContactService::class)->send([
            'name' => '<script>alert(1)</script>',
            'email' => 'cliente@example.com',
            'phone_country' => '+503',
            'phone' => '7000-0000',
            'topic' => 'order',
            'message' => "Consulta segura\n<script>alert(2)</script>",
        ], 'SV');

        $request = Http::recorded()[0][0];
        $html = $request->data()['html_body'];

        $this->assertStringStartsWith('WEB-', $reference);
        $this->assertSame(['servicio@example.com'], $request->data()['to']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Pedido o compra', $html);
    }
}
