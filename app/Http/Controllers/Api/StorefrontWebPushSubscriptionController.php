<?php

namespace App\Http\Controllers\Api;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\WebPushSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontWebPushSubscriptionController extends BaseController
{
    public function __construct(private readonly WebPushSubscriptionService $subscriptions) {}

    public function store(Request $request, string $country): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'browser' => ['nullable', 'string', 'max:100'],
            'device' => ['nullable', 'string', 'max:100'],
            'operating_system' => ['nullable', 'string', 'max:100'],
            'language' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);
        [$visitor, $customer] = $this->identity($request);

        return $this->success(
            $this->subscriptions->register($country, $visitor, $customer, $data, (string) $request->userAgent()),
            'Suscripcion Web Push registrada.',
        );
    }

    public function destroy(Request $request, string $country): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'permission' => ['required', 'in:granted,denied,default'],
        ]);
        [$visitor, $customer] = $this->identity($request);

        return $this->success(
            $this->subscriptions->revoke($country, $visitor, $customer, $data['token'], $data['permission']),
            'Suscripcion Web Push revocada.',
        );
    }

    /** @return array{StorefrontVisitor, StorefrontCustomer|null} */
    private function identity(Request $request): array
    {
        /** @var StorefrontVisitor|null $visitor */
        $visitor = $request->attributes->get('storefrontVisitor');
        abort_unless($visitor instanceof StorefrontVisitor, 401);
        $user = Auth::guard('sanctum')->user();

        return [$visitor, $user instanceof StorefrontCustomer ? $user : null];
    }
}
