<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StorefrontCheckoutValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCheckoutValidationController extends Controller
{
    public function __invoke(Request $request, StorefrontCheckoutValidationService $service): JsonResponse
    {
        $payload = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'fulfillment' => ['required', 'array'],
            'fulfillment.method' => ['required', 'string', 'in:home_delivery,store_pickup'],
            'fulfillment.storeCode' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['required', 'string', 'max:120'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['required', 'string', 'max:40'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $result = $service->validate(
            $payload['country'],
            $payload['fulfillment'],
            $payload['items'],
        );

        return response()->json([
            'ok' => (bool) $result['ok'],
            'message' => $result['message'] ?? null,
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }
}
