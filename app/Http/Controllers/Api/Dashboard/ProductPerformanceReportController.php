<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ProductPerformanceReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductPerformanceReportController extends BaseController
{
    public function __invoke(Request $request, ProductPerformanceReportService $report)
    {
        abort_unless($request->user()?->tokenCan('dashboard'), 403);
        $data = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'period' => ['required', Rule::in(['7D', '14D', '30D', 'ANUAL'])],
            'tab' => ['required', Rule::in(['summary', 'sales', 'views', 'favorites', 'cart'])],
            'brand' => ['nullable', 'string', 'max:50'], 'category' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'in:10,20,50,100'],
        ]);
        $data['page'] = (int) ($data['page'] ?? 1);
        $data['perPage'] = (int) ($data['perPage'] ?? 20);
        return $this->success($report->report($data));
    }
}
