<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StorefrontOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontOrderController extends Controller
{
    public function store(Request $request, StorefrontOrderService $service): JsonResponse
    {
        $payload = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'guestCartId' => ['required', 'string', 'max:250'],
            'customer' => ['required', 'array'],
            'customer.firstName' => ['required', 'string', 'max:30'],
            'customer.lastName' => ['required', 'string', 'max:30'],
            'customer.email' => ['required', 'email', 'max:50'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.document' => ['nullable', 'string', 'max:50'],
            'fulfillment' => ['required', 'array'],
            'fulfillment.method' => ['required', 'string', 'in:home_delivery,store_pickup'],
            'fulfillment.storeCode' => ['nullable', 'string', 'max:40'],
            'fulfillment.storeName' => ['nullable', 'string', 'max:120'],
            'fulfillment.city' => ['nullable', 'string', 'max:50'],
            'fulfillment.state' => ['nullable', 'string', 'max:50'],
            'fulfillment.addressLine1' => ['nullable', 'string', 'max:200'],
            'fulfillment.reference' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['required', 'string', 'max:120'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['required', 'string', 'max:40'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $result = $service->create($payload);

        return response()->json([
            'ok' => (bool) $result['ok'],
            'message' => $result['message'],
            'data' => $result,
        ], (int) $result['status']);
    }
}
