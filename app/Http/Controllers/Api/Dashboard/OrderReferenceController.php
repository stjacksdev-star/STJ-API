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

    public function process(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'reference' => ['required', 'string', 'max:60'],
            'ticket' => ['required', 'string', 'max:100'],
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
                $validated['actor'] ?? [],
            ),
            'Pedido procesado'
        );
    }
}
