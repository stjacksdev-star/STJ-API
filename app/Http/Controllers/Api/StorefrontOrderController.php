<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CartOperationConflict;
use App\Http\Controllers\Controller;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontOrderService;
use App\Services\CheckoutEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontOrderController extends Controller
{
    public function store(Request $request, string $country, StorefrontOrderService $service, CheckoutEventService $events): JsonResponse
    {
        $payload = $request->validate([
            'operation_uuid' => ['required', 'uuid'],
            'customer' => ['required', 'array'],
            'customer.firstName' => ['required', 'string', 'max:30'],
            'customer.lastName' => ['required', 'string', 'max:30'],
            'customer.email' => ['required', 'email', 'max:50'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.documentType' => ['required', 'in:DUI,DPI,Cédula,Carné de residente,Licencia de conducir,Pasaporte,Otro'],
            'customer.document' => ['required', 'string', 'max:50'],
            'customer.countryId' => ['required', 'integer', 'exists:stj_world_countries,id'],
            'customer.stateId' => ['required', 'integer', 'exists:stj_world_states,id'],
            'customer.cityId' => ['required', 'integer', 'exists:stj_world_cities,id'],
            'customer.address' => ['required', 'string', 'max:200'],
            'delivery' => ['nullable', 'array'],
            'delivery.city_id' => ['nullable', 'integer'],
            'delivery.state_id' => ['nullable', 'integer'],
            'delivery.city' => ['nullable', 'string', 'max:50'],
            'delivery.state' => ['nullable', 'string', 'max:50'],
            'delivery.addressLine1' => ['nullable', 'string', 'max:200'],
            'delivery.reference' => ['nullable', 'string', 'max:200'],
            'pickup' => ['nullable', 'array'],
            'pickup.samePerson' => ['nullable', 'boolean'],
            'pickup.person' => ['nullable', 'string', 'max:100', 'not_regex:/[<>]/'],
            'pickup.phone' => ['nullable', 'string', 'max:100', 'not_regex:/[<>]/'],
            'pickup.identification' => ['nullable', 'string', 'max:50', 'not_regex:/[<>]/'],
            'payment_type' => ['sometimes', 'in:TARJETA,EFECTIVO'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $visitor = $request->attributes->get('storefrontVisitor');
        abort_unless($visitor instanceof StorefrontVisitor, 401);
        $user = Auth::guard('sanctum')->user();
        $customer = $user instanceof StorefrontCustomer ? $user : null;

        $event = ['country' => $country, 'flow' => 'ORDER', 'stage' => 'ORDER_CREATION', 'event' => 'ORDER_CREATION_STARTED', 'result' => 'STARTED', 'operation_uuid' => $payload['operation_uuid'], 'checkout_type' => data_get($payload, 'delivery.method') === 'store_pickup' ? 'TIENDA' : 'DOMICILIO', 'payment_method' => $payload['payment_type'] ?? 'TARJETA'];
        $events->record($request, $event, $visitor, $customer);

        try {
            $result = $service->createFromCart($country, $visitor, $customer, $payload);
        } catch (CartOperationConflict $exception) {
            $events->record($request, array_merge($event, ['event' => 'ORDER_CREATION_FAILED', 'result' => 'ERROR', 'severity' => 'WARNING', 'code' => 'OPERATION_CONFLICT', 'message' => $exception->getMessage(), 'http_status' => 409]), $visitor, $customer);
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 409);
        } catch (\Throwable $exception) {
            $events->record($request, array_merge($event, ['event' => 'ORDER_CREATION_FAILED', 'result' => 'ERROR', 'severity' => 'ERROR', 'code' => class_basename($exception), 'message' => $events->exceptionMessage($exception)]), $visitor, $customer);
            throw $exception;
        }

        $events->record($request, array_merge($event, ['event' => 'ORDER_CREATED', 'result' => 'SUCCESS', 'order_id' => data_get($result, 'order.pedidoId'), 'payment_id' => data_get($result, 'order.pagoId'), 'currency' => data_get($result, 'order.currency'), 'amount' => data_get($result, 'order.total')]), $visitor, $customer);

        return response()->json(['ok' => true, 'message' => $result['message'], 'data' => $result], 201);
    }
}
