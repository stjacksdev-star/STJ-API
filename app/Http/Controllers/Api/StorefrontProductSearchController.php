<?php

namespace App\Http\Controllers\Api;

use App\Services\StorefrontProductSearchService;
use Illuminate\Http\Request;

class StorefrontProductSearchController extends BaseController
{
    public function __invoke(Request $request, string $country, StorefrontProductSearchService $search)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        return $this->success(
            $search->suggestions($country, $validated['q'], (int) ($validated['limit'] ?? 8)),
            'Sugerencias de productos obtenidas',
        );
    }
}
