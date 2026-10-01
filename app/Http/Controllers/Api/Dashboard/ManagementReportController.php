<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ManagementCyberMondayReportService;
use App\Services\Dashboard\ManagementDailySalesReportService;
use App\Services\Dashboard\ManagementPlatformSalesReportService;
use App\Services\Dashboard\ManagementVisitDetailsReportService;
use Illuminate\Http\Request;

class ManagementReportController extends BaseController
{
    public function __construct(
        private readonly ManagementDailySalesReportService $reports,
        private readonly ManagementCyberMondayReportService $cyberMonday,
        private readonly ManagementPlatformSalesReportService $platformSales,
        private readonly ManagementVisitDetailsReportService $visitDetails,
    ) {}

    public function dailySales(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $filters = $request->validate($this->rules());

        return $this->success($this->reports->report((int) $filters['month'], (int) ($filters['country'] ?? 0)), 'Reporte de venta por dia obtenido');
    }

    public function dailySalesExport(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $filters = $request->validate($this->rules());
        $file = $this->reports->export((int) $filters['month'], (int) ($filters['country'] ?? 0));

        return response($file['contents'], 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function monthlySales(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate($this->monthlyRules());

        return $this->success($this->reports->monthlyReport((int) ($filters['month'] ?? 0), (int) ($filters['country'] ?? 0)), 'Reporte de venta por mes obtenido');
    }

    public function monthlySalesExport(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate($this->monthlyRules());
        $file = $this->reports->exportMonthly((int) ($filters['month'] ?? 0), (int) ($filters['country'] ?? 0));

        return response($file['contents'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function cyberMonday(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate($this->cyberMondayRules());

        return $this->success($this->cyberMonday->report((int) $filters['country']), 'Reporte Cyber Monday obtenido');
    }

    public function cyberMondayExport(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate($this->cyberMondayRules());
        $file = $this->cyberMonday->export((int) $filters['country']);

        return response($file['contents'], 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'between:1,12'],
            'country' => ['nullable', 'integer', 'in:0,1,2,3,7'],
        ];
    }

    private function monthlyRules(): array
    {
        return [
            'month' => ['nullable', 'integer', 'between:0,12'],
            'country' => ['nullable', 'integer', 'in:0,1,2,3,7'],
        ];
    }

    private function cyberMondayRules(): array
    {
        return ['country' => ['required', 'integer', 'in:1,2,3']];
    }

    public function platformSales(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate($this->platformRules());

        return $this->success($this->platformSales->report($filters['country'], $filters['startDate'], $filters['endDate']), 'Reporte de venta por plataforma obtenido');
    }

    public function platformSalesOrders(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate([...$this->platformRules(), 'platform' => ['required', 'in:WEB,APP-IOS,APP-ANDROID,APP-SIN-PLATAFORMA'], 'type' => ['required', 'in:TIENDA,DOMICILIO']]);

        return $this->success($this->platformSales->orders($filters['country'], $filters['startDate'], $filters['endDate'], $filters['platform'], $filters['type']), 'Pedidos por plataforma obtenidos');
    }

    private function platformRules(): array
    {
        return ['country' => ['required', 'string', 'max:3'], 'startDate' => ['required', 'date'], 'endDate' => ['required', 'date']];
    }

    public function visitDetails(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate([
            'startDate' => ['required', 'date'], 'endDate' => ['required', 'date'],
            'country' => ['nullable', 'string', 'max:7'],
            'platform' => ['nullable', 'in:TODAS,WEB,APP-IOS,APP-ANDROID'],
        ]);

        return $this->success($this->visitDetails->report($filters['startDate'], $filters['endDate'], $filters['country'] ?? 'GENERAL', $filters['platform'] ?? 'TODAS'), 'Reporte detallado de visitas obtenido');
    }

    public function visitDetailsExport(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }
        $filters = $request->validate([
            'startDate' => ['required', 'date'], 'endDate' => ['required', 'date'],
            'country' => ['nullable', 'string', 'max:7'],
            'platform' => ['nullable', 'in:TODAS,WEB,APP-IOS,APP-ANDROID'],
        ]);
        $file = $this->visitDetails->export($filters['startDate'], $filters['endDate'], $filters['country'] ?? 'GENERAL', $filters['platform'] ?? 'TODAS');

        return response($file['contents'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
