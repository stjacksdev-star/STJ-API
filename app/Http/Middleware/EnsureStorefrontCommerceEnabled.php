<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStorefrontCommerceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $country = strtolower(trim((string) $request->route('country')));

        if (in_array($country, config('storefront.catalog_only_countries', []), true)) {
            abort(403, 'La compra en línea todavía no está habilitada para este país.');
        }

        return $next($request);
    }
}
