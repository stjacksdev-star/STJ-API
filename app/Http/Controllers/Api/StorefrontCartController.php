<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CartOperationConflict;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontCartCouponService;
use App\Services\StorefrontCartService;
use App\Services\CheckoutEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontCartController extends BaseController
{
    public function __construct(private StorefrontCartService $carts, private StorefrontCartCouponService $coupons, private CheckoutEventService $checkoutEvents) {}

    public function storeCoupon(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'code' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email:rfc', 'max:255']]);

        return $this->mutation(fn () => $this->coupons->add($country, $this->visitor($request), $this->customer(), $data), 'Cupón agregado.');
    }

    public function availableCoupons(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email:rfc', 'max:255']]);

        return $this->success($this->coupons->available($country, $this->customer(), $data['email'] ?? null), 'Cupones disponibles.');
    }

    public function revalidateCoupons(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email:rfc', 'max:255']]);

        return $this->success(
            $this->coupons->revalidateForIdentity($country, $this->visitor($request), $this->customer(), (string) ($data['email'] ?? '')),
            'Cupones recalculados.',
        );
    }

    public function destroyCoupon(Request $request, string $country, int $application): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'email' => ['nullable', 'email:rfc', 'max:255']]);

        return $this->mutation(fn () => $this->coupons->remove($country, $application, $this->visitor($request), $this->customer(), $data), 'Cupón eliminado.');
    }

    public function show(Request $request, string $country): JsonResponse
    {
        return $this->success($this->carts->get($country, $this->visitor($request), $this->customer()), 'Carrito recuperado.');
    }

    public function storeItem(Request $request, string $country): JsonResponse
    {
        $data = $request->validate($this->itemRules());

        return $this->mutation(fn () => $this->carts->add($country, $this->visitor($request), $this->customer(), $data), 'Producto agregado.');
    }

    public function updateItem(Request $request, string $country, int $item): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'], 'selected' => ['sometimes', 'boolean'], 'inventory_scope' => ['sometimes', 'in:cart,checkout']]);
        if (! array_key_exists('quantity', $data) && ! array_key_exists('selected', $data)) {
            return $this->error('Debe indicar quantity o selected.', 422);
        }

        return $this->mutation(fn () => $this->carts->update($country, $item, $this->visitor($request), $this->customer(), $data), 'Carrito actualizado.');
    }

    public function destroyItem(Request $request, string $country, int $item): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'delivery' => ['nullable', 'array'], 'delivery.city_id' => ['nullable', 'integer'], 'delivery.state_id' => ['nullable', 'integer'], 'delivery.addressLine1' => ['nullable', 'string', 'max:200'], 'delivery.reference' => ['nullable', 'string', 'max:200']]);

        return $this->mutation(fn () => $this->carts->remove($country, $item, $this->visitor($request), $this->customer(), $data), 'Producto eliminado.');
    }

    public function sync(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'items' => ['array', 'max:100'], 'items.*.product_id' => ['required', 'integer'], 'items.*.sku' => ['required', 'string', 'max:50'], 'items.*.size' => ['required', 'string', 'max:10'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99']]);

        return $this->mutation(fn () => $this->carts->sync($country, $this->visitor($request), $this->customer(), $data), 'Carrito sincronizado.');
    }

    public function validateForCheckout(Request $request, string $country): JsonResponse
    {
        return $this->success(
            $this->carts->validateForCheckout($country, $this->visitor($request), $this->customer()),
            'Carrito validado con las reglas de checkout.',
        );
    }

    public function merge(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid']]);
        $customer = $this->customer();
        if (! $customer) {
            return $this->error('La combinacion requiere un cliente autenticado.', 401);
        }

        return $this->mutation(fn () => $this->carts->merge($country, $this->visitor($request), $customer, $data), 'Carritos combinados.');
    }

    public function startCheckout(Request $request, string $country): JsonResponse
    {
        $data = $request->validate([
            'operation_uuid' => ['required', 'uuid'],
            'delivery' => ['nullable', 'array'],
            'delivery.city_id' => ['nullable', 'integer'],
            'delivery.state_id' => ['nullable', 'integer'],
            'delivery.addressLine1' => ['nullable', 'string', 'max:200'],
            'delivery.reference' => ['nullable', 'string', 'max:200'],
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $visitor = $this->visitor($request); $customer = $this->customer();
        $event = ['country' => $country, 'flow' => 'CHECKOUT', 'stage' => 'INVENTORY', 'event' => 'INVENTORY_VALIDATION_STARTED', 'result' => 'STARTED', 'operation_uuid' => $data['operation_uuid']];
        $this->checkoutEvents->record($request, $event, $visitor, $customer);
        try {
            $result = $this->carts->startCheckout($country, $visitor, $customer, $data);
        } catch (CartOperationConflict $exception) {
            $this->checkoutEvents->record($request, array_merge($event, ['event' => 'CART_VERSION_CONFLICT', 'result' => 'ERROR', 'severity' => 'WARNING', 'code' => 'OPERATION_CONFLICT', 'message' => $exception->getMessage(), 'http_status' => 409]), $visitor, $customer);
            return $this->error($exception->getMessage(), 409);
        } catch (\Throwable $exception) {
            $message = $this->checkoutEvents->exceptionMessage($exception);
            $inventory = str_contains(strtolower($message), 'inventario') || str_contains(strtolower($message), 'stock') || str_contains(strtolower($message), 'existencia');
            $this->checkoutEvents->record($request, array_merge($event, ['event' => $inventory ? 'INVENTORY_VALIDATION_FAILED' : 'CHECKOUT_START_FAILED', 'result' => 'ERROR', 'severity' => 'WARNING', 'code' => class_basename($exception), 'message' => $message]), $visitor, $customer);
            throw $exception;
        }
        $this->checkoutEvents->record($request, array_merge($event, ['event' => 'INVENTORY_VALIDATION_PASSED', 'result' => 'SUCCESS', 'metadata' => ['itemsCount' => count(data_get($result, 'checkout.lines', [])), 'inventorySource' => data_get($result, 'checkout.inventorySource.usedSource')]]), $visitor, $customer);

        return $this->success($result, 'Checkout iniciado.');
    }

    public function previewFulfillment(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['fulfillment_type' => ['required', 'in:DOMICILIO,TIENDA'], 'store_code' => ['nullable', 'string', 'max:15']]);

        return $this->success($this->carts->previewFulfillment($country, $this->visitor($request), $this->customer(), $data), 'Vista previa del cambio obtenida.');
    }

    public function applyFulfillment(Request $request, string $country): JsonResponse
    {
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'fulfillment_type' => ['required', 'in:DOMICILIO,TIENDA'], 'store_code' => ['nullable', 'string', 'max:15'], 'confirm_affected' => ['sometimes', 'boolean']]);

        return $this->mutation(fn () => $this->carts->applyFulfillment($country, $this->visitor($request), $this->customer(), $data), 'Contexto actualizado.');
    }

    private function itemRules(): array
    {
        return ['operation_uuid' => ['required', 'uuid'], 'product_id' => ['required', 'integer'], 'sku' => ['required', 'string', 'max:50'], 'size' => ['required', 'string', 'max:10'], 'quantity' => ['required', 'integer', 'min:1', 'max:99'], 'recommendation_placement' => ['nullable', 'in:PDP_RELATED,CART_RECOMMENDATIONS,ADD_TO_CART_RECOMMENDATIONS,ADD_TO_CART_DRAWER,RECENTLY_VIEWED'], 'recommendation_reason' => ['nullable', 'in:SAME_COLLECTION,SAME_CATEGORY,SAME_BRAND,SIMILAR_PRICE,POPULAR,RECENTLY_VIEWED,PURCHASE_HISTORY,PURCHASE_CHARACTER,PURCHASE_CATEGORY_GENDER,PURCHASE_BRAND_COLLECTION'], 'recommendation_position' => ['nullable', 'integer', 'min:1', 'max:10']];
    }

    private function visitor(Request $request): StorefrontVisitor
    {
        return $request->attributes->get('storefrontVisitor');
    }

    private function customer(): ?StorefrontCustomer
    {
        $user = Auth::guard('sanctum')->user();

        return $user instanceof StorefrontCustomer ? $user : null;
    }

    private function mutation(callable $callback, string $message): JsonResponse
    {
        try {
            return $this->success($callback(), $message);
        } catch (CartOperationConflict $e) {
            return $this->error($e->getMessage(), 409);
        }
    }
}
