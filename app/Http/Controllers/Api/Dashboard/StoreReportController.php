<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\StoreReportService;
use Illuminate\Http\Request;

class StoreReportController extends BaseController
{
    public function __construct(
        private readonly StoreReportService $reports,
    ) {
    }

    public function catalog(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['nullable', 'string', 'max:3'],
        ]);

        return $this->success([
            'countries' => $this->reports->countries(),
            'stores' => filled($validated['country'] ?? null)
                ? $this->reports->storesForCountry((string) $validated['country'])
                : [],
        ], 'Catalogo de reportes de tienda obtenido');
    }

    public function virtualCut(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
        ]);

        return $this->success(
            $this->reports->virtualCut($validated),
            'Corte virtual obtenido'
        );
    }

    public function pendingItems(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'in:DOMICILIO,TIENDA'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
        ]);

        return $this->success(
            $this->reports->pendingItems($validated),
            'Articulos pendientes obtenidos'
        );
    }

    public function pendingItemsByOrder(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['nullable', 'string', 'max:20'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
        ]);

        return $this->success(
            $this->reports->pendingItemsByOrder($validated),
            'Articulos pendientes por pedido obtenidos'
        );
    }
}
