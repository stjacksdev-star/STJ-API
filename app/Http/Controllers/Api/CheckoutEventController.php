<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\CheckoutEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CheckoutEventController extends Controller
{
    public function __invoke(Request $request, string $country, CheckoutEventService $events): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'operation_uuid' => ['nullable', 'uuid'],
            'event' => ['required', Rule::in(['CHECKOUT_CONFIRM_CLICKED', 'CHECKOUT_VALIDATION_FAILED', 'CHECKOUT_SESSION_EXPIRED'])],
            'stage' => ['required', Rule::in(['CUSTOMER_DATA', 'FULFILLMENT', 'CARD_DATA', 'SESSION'])],
            'result' => ['required', Rule::in(['STARTED', 'REJECTED', 'EXPIRED'])],
            'checkout_type' => ['nullable', Rule::in(['DOMICILIO', 'TIENDA'])],
            'payment_method' => ['nullable', Rule::in(['TARJETA', 'EFECTIVO'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'metadata' => ['nullable', 'array'],
            'metadata.missingFields' => ['nullable', 'array', 'max:30'],
            'metadata.missingFields.*' => ['string', 'max:80'],
            'metadata.invalidFields' => ['nullable', 'array', 'max:30'],
            'metadata.invalidFields.*' => ['string', 'max:80'],
            'metadata.itemsCount' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        $visitor = $request->attributes->get('storefrontVisitor');
        abort_unless($visitor instanceof StorefrontVisitor, 401);
        $user = Auth::guard('sanctum')->user();
        $events->record($request, $data + ['country' => $country, 'flow' => 'CHECKOUT', 'severity' => $data['result'] === 'STARTED' ? 'INFO' : 'WARNING'], $visitor, $user instanceof StorefrontCustomer ? $user : null);

        return response()->json(['ok' => true], 202);
    }
}
