<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\SalesKpiService;
use Illuminate\Http\Request;

class SalesKpiController extends BaseController
{
    public function __construct(
        private readonly SalesKpiService $sales,
    ) {
    }

    public function show(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['nullable', 'string', 'max:3'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);

        return $this->success(
            $this->sales->kpi(
                $validated['country'] ?? null,
                $validated['startDate'] ?? null,
                $validated['endDate'] ?? null,
            ),
            'KPI de ventas obtenido'
        );
    }

    public function orders(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'origin' => ['nullable', 'string', 'max:20'],
            'checkout' => ['nullable', 'string', 'max:20'],
            'pending' => ['nullable', 'boolean'],
            'store' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->sales->orders($validated),
            'Detalle de pedidos obtenido'
        );
    }
}
