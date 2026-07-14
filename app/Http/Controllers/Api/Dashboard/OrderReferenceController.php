<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\OrderReferenceService;
use Illuminate\Http\Request;

class OrderReferenceController extends BaseController
{
    public function __construct(
        private readonly OrderReferenceService $orders,
    ) {
    }

    public function show(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
        ]);

        return $this->success(
            $this->orders->show($validated['reference'], $validated['country']),
            'Pedido obtenido'
        );
    }

    public function search(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'store' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success(
            $this->orders->search($validated),
            'Pedidos encontrados'
        );
    }

    public function paymentAttempts(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'order' => ['required', 'integer', 'min:1'],
            'store' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->orders->paymentAttempts((int) $validated['order'], $validated['country'], $validated['store'] ?? null),
            'Intentos de pago obtenidos'
        );
    }

    public function refunds(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:SI,NO'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);

        return $this->success(
            $this->orders->refunds($validated),
            'Devoluciones obtenidas'
        );
    }

    public function product(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'sku' => ['required', 'string', 'max:60'],
            'size' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->orders->lookupProduct($validated['sku'], $validated['country'], $validated['size'] ?? null),
            'Articulo validado'
        );
    }

    public function updateLine(Request $request, int $line)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:60'],
            'size' => ['required', 'string', 'max:20'],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->updateLine($line, $validated, $validated['actor'] ?? []),
            'Linea actualizada'
        );
    }

    public function updateData(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:50'],
            'phone' => ['required', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'billingAddress' => ['required', 'string', 'max:200'],
            'shippingAddress' => ['nullable', 'string', 'max:200'],
            'shippingReference' => ['nullable', 'string', 'max:200'],
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->updateData($validated['reference'], $validated['country'], $validated, $validated['actor'] ?? []),
            'Datos del pedido actualizados'
        );
    }

    public function shippingManagement(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
            'actor' => ['required', 'array'],
            'actor.permissions' => ['required', 'array'],
            'actor.permissions.*' => ['string', 'max:100'],
        ]);

        return $this->success(
            $this->orders->shippingManagement($validated['reference'], $validated['actor']),
            'Pedido obtenido'
        );
    }

    public function updateShippingManagement(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
            'shippingType' => ['required', 'string', 'max:50'],
            'urbanId' => ['nullable', 'string', 'max:100'],
            'shippingId' => ['nullable', 'string', 'max:100'],
            'shippingCost' => ['required', 'numeric', 'min:0'],
            'shippingCostText' => ['nullable', 'string', 'max:200'],
            'finalShippingCost' => ['required', 'numeric', 'min:0'],
            'freeShipping' => ['required', 'in:SI,NO'],
            'routeAt' => ['nullable', 'date'],
            'addressType' => ['nullable', 'string', 'max:30'],
            'samePerson' => ['required', 'in:SI,NO'],
            'sameAddress' => ['required', 'in:SI,NO'],
            'country' => ['required', 'string', 'max:10'],
            'latitude' => ['nullable', 'string', 'max:50'],
            'longitude' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:200'],
            'referencePoint' => ['nullable', 'string', 'max:200'],
            'departmentId' => ['nullable', 'string', 'max:30'],
            'municipalityId' => ['nullable', 'string', 'max:30'],
            'department' => ['nullable', 'string', 'max:100'],
            'municipality' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'receiverName' => ['nullable', 'string', 'max:100'],
            'receiverPhone' => ['nullable', 'string', 'max:100'],
            'saveType' => ['nullable', 'string', 'max:20'],
            'actor' => ['required', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.permissions' => ['required', 'array'],
            'actor.permissions.*' => ['string', 'max:100'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->updateShippingManagement($validated['reference'], $validated, $validated['actor']),
            'Datos de envio actualizados'
        );
    }

    public function process(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
            'ticket' => ['required', 'string', 'max:100'],
            'refundObservation' => ['nullable', 'string', 'max:2000'],
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->processOrder(
                $validated['reference'],
                $validated['country'],
                $validated['ticket'],
                $validated['refundObservation'] ?? null,
                $validated['actor'] ?? [],
            ),
            'Pedido procesado'
        );
    }

    public function deliver(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.countryId' => ['nullable'],
            'actor.storeId' => ['nullable'],
            'actor.storeCode' => ['nullable', 'string', 'max:20'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->deliverOrder(
                $validated['reference'],
                $validated['country'],
                $validated['actor'] ?? [],
            ),
            'Pedido entregado'
        );
    }

    public function markPackedForPickup(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.countryId' => ['nullable'],
            'actor.storeId' => ['nullable'],
            'actor.storeCode' => ['nullable', 'string', 'max:20'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->markOrderPackedForPickup(
                $validated['reference'],
                $validated['country'],
                $validated['actor'] ?? [],
            ),
            'Pedido preparado para entrega'
        );
    }

    public function markInRoute(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:150'],
            'actor.username' => ['nullable', 'string', 'max:100'],
            'actor.countryId' => ['nullable'],
            'actor.storeId' => ['nullable'],
            'actor.storeCode' => ['nullable', 'string', 'max:20'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->orders->markOrderInRoute(
                $validated['reference'],
                $validated['country'],
                $validated['actor'] ?? [],
            ),
            'Pedido en ruta'
        );
    }
}
