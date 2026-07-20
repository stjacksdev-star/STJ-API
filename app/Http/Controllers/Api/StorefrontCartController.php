<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CartOperationConflict;
use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use App\Services\StorefrontCartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontCartController extends BaseController
{
    public function __construct(private StorefrontCartService $carts) {}

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
        $data = $request->validate(['operation_uuid' => ['required', 'uuid'], 'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'], 'selected' => ['sometimes', 'boolean']]);
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
        ]);

        return $this->mutation(fn () => $this->carts->startCheckout($country, $this->visitor($request), $this->customer(), $data), 'Checkout iniciado.');
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
        return ['operation_uuid' => ['required', 'uuid'], 'product_id' => ['required', 'integer'], 'sku' => ['required', 'string', 'max:50'], 'size' => ['required', 'string', 'max:10'], 'quantity' => ['required', 'integer', 'min:1', 'max:99']];
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
