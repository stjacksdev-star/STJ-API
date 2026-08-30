<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Services\Mobile\MobilePushSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MobilePushSubscriptionController extends Controller
{
    public function __construct(private readonly MobilePushSubscriptionService $subscriptions) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'max:500'],
            'installationId' => ['required', 'uuid'],
            'sessionCode' => ['nullable', 'string', 'max:150'],
            'platform' => ['required', Rule::in(['IOS', 'ANDROID', 'WEB'])],
            'countryId' => ['required', 'integer', Rule::exists('stj_paises', 'pai_id')],
            'permission' => ['required', Rule::in(['GRANTED', 'DENIED', 'DEFAULT'])],
            'appVersion' => ['nullable', 'string', 'max:40'],
            'appBuild' => ['nullable', 'string', 'max:40'],
            'environment' => ['required', Rule::in(['TEST', 'PRODUCTION'])],
            'device' => ['nullable', 'string', 'max:100'],
            'operatingSystem' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);
        $user = $request->user('sanctum');

        return response()->json($this->subscriptions->register(
            $request,
            $data,
            $user instanceof StorefrontCustomer ? $user : null,
        ), 201);
    }
}
