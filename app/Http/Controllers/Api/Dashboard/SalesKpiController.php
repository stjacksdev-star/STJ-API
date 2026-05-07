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

    public function regionalSalesChart(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);

        return $this->success(
            $this->sales->regionalSalesChart(
                $validated['startDate'] ?? null,
                $validated['endDate'] ?? null,
            ),
            'Grafico regional de ventas obtenido'
        );
    }

    public function conversionChart(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'country' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->sales->conversionChart(
                $validated['startDate'] ?? null,
                $validated['endDate'] ?? null,
                $validated['country'] ?? null,
            ),
            'Conversion de ventas obtenida'
        );
    }

    public function visitsChart(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'country' => ['nullable', 'string', 'max:20'],
            'previousStartDate' => ['nullable', 'date'],
            'previousEndDate' => ['nullable', 'date'],
        ]);

        return $this->success(
            $this->sales->visitsChart(
                $validated['startDate'] ?? null,
                $validated['endDate'] ?? null,
                $validated['country'] ?? null,
                $validated['previousStartDate'] ?? null,
                $validated['previousEndDate'] ?? null,
            ),
            'Visitas obtenidas'
        );
    }

    public function satisfaction(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->sales->satisfaction(),
            'Indicadores de satisfaccion obtenidos'
        );
    }

    public function categories(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);

        return $this->success(
            $this->sales->categorySales(
                $validated['startDate'] ?? null,
                $validated['endDate'] ?? null,
            ),
            'Venta por categorias obtenida'
        );
    }

    public function segments(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->sales->segments(),
            'Segmentos obtenidos'
        );
    }

    public function paymentForms(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->sales->paymentForms(),
            'Formas de pago obtenidas'
        );
    }

    public function geographic(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->sales->geographicSales(),
            'Venta geografica obtenida'
        );
    }

    public function app(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
        ]);

        return $this->success(
            $this->sales->appInstallations($validated['year'] ?? null),
            'Instalaciones APP obtenidas'
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
            'statuses' => ['nullable', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->sales->orders($validated),
            'Detalle de pedidos obtenido'
        );
    }
}
