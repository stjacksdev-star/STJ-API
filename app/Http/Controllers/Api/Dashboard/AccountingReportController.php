<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\AccountingReportService;
use Illuminate\Http\Request;

class AccountingReportController extends BaseController
{
    public function __construct(
        private readonly AccountingReportService $reports,
    ) {
    }

    public function count3(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->rules());

        return $this->success(
            $this->reports->countAccounting3($validated),
            'Total de contabilidad 3 obtenido'
        );
    }

    public function salesByStore(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['required', 'string', 'max:20'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
        ]);

        return $this->success(
            $this->reports->salesByStore($validated),
            'Reporte de venta por tienda obtenido'
        );
    }

    public function export3(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->rules());
        $file = $this->reports->exportAccounting3($validated);

        return response($file['contents'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'country' => ['required', 'string', 'max:3'],
            'store' => ['required', 'string', 'max:20'],
            'paymentType' => ['required', 'in:TARJETA,EFECTIVO,TODO'],
            'status' => ['required', 'in:FACTURADO,PENDIENTE,TODO'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date'],
        ];
    }
}
