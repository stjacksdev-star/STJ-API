<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StorefrontSubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontSubscriberController extends Controller
{
    public function store(Request $request, string $country, StorefrontSubscriberService $service): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'acceptsPromotions' => ['accepted'],
        ]);

        $result = $service->subscribe($country, $payload['email']);

        return response()->json([
            'ok' => (bool) $result['ok'],
            'message' => $result['message'],
            'data' => [
                'subscriber' => $result['subscriber'],
            ],
        ], (int) $result['status']);
    }
}
