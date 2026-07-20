<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PowerTranzReturnSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_token_is_rejected_without_order_information(): void
    {
        $url = '/api/storefront/payments/powertranz/return/sv/'.str_repeat('A', 64);
        $response = $this->postJson($url, ['SpiToken' => 'unknown-token', 'TransactionIdentifier' => 'unknown-operation', 'Response' => 'unknown']);

        $response->assertUnprocessable()->assertJsonMissingPath('order')->assertJsonMissingPath('data.orderId');
        $response->assertHeaderMissing('Set-Cookie');
        $this->assertStringNotContainsString('pedido', strtolower($response->getContent()));
    }

    public function test_get_cannot_process_the_return_and_route_is_public_but_throttled(): void
    {
        $url = '/api/storefront/payments/powertranz/return/sv/'.str_repeat('A', 64);
        $this->getJson($url)->assertMethodNotAllowed();

        $route = Route::getRoutes()->getByName('powertranz.return');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
    }
}
