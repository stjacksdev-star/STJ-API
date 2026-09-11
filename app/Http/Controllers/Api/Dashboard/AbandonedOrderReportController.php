<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\AbandonedOrderReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AbandonedOrderReportController extends BaseController
{
    public function __invoke(Request $request, AbandonedOrderReportService $report)
    {
        abort_unless($request->user()?->tokenCan('dashboard'), 403);
        $data = $request->validate([
            'country' => ['required', 'string', 'max:3'], 'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'status' => ['nullable', Rule::in(['SIN_PAGO', 'PENDIENTE', 'DENEGADA', 'TIMEOUT', 'REVERSION', 'DEVOLUCION'])],
            'search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);
        $data['page'] = (int) ($data['page'] ?? 1);
        $data['perPage'] = (int) ($data['perPage'] ?? 20);
        return $this->success($report->report($data));
    }
}
