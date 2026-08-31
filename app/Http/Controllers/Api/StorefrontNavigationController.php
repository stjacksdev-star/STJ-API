<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontNavigationService;
use Illuminate\Http\Request;

class StorefrontNavigationController extends BaseController
{
    public function __invoke(Request $request, string $country, StorefrontNavigationService $navigation)
    {
        $payload = $navigation->getOrBuild($country);

        if (! $payload) {
            return $this->error('País no soportado', 404);
        }

        $etag = '"'.sha1(json_encode($payload)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return $this->success($payload, 'Navegación del storefront obtenida')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400');
    }
}
