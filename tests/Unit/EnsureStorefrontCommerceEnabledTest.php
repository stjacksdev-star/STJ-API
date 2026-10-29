<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureStorefrontCommerceEnabled;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureStorefrontCommerceEnabledTest extends TestCase
{
    public function test_catalog_only_country_cannot_access_commerce_routes(): void
    {
        config(['storefront.catalog_only_countries' => ['ve']]);
        $request = Request::create('/api/storefront/cart/ve', 'GET');
        $request->setRouteResolver(fn () => new class {
            public function parameter(string $key, mixed $default = null): mixed { return $key === 'country' ? 've' : $default; }
        });

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);

        app(EnsureStorefrontCommerceEnabled::class)->handle($request, fn () => response()->json(['ok' => true]));
    }
}
