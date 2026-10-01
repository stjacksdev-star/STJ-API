<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ManagementDailySalesReportService;
use Illuminate\Http\Request;

class ManagementReportController extends BaseController
{
    public function __construct(private readonly ManagementDailySalesReportService $reports) {}

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

    private function rules(): array
    {
        return [
            'month' => ['required', 'integer', 'between:1,12'],
            'country' => ['nullable', 'integer', 'in:0,1,2,3,7'],
        ];
    }
}
