<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ProductCountryService;
use Illuminate\Http\Request;

class ProductCountryController extends BaseController
{
    public function __construct(
        private readonly ProductCountryService $products,
    ) {
    }

    public function countries(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            ['countries' => $this->products->countries()],
            'Paises obtenidos'
        );
    }

    public function import(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        return $this->success(
            $this->products->import($validated['file'], (int) $validated['country']),
            'Importacion de productos por pais finalizada'
        );
    }

    public function deactivate(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        return $this->success(
            $this->products->deactivate($validated['file'], (int) $validated['country'], $validated['reason']),
            'Baja de productos por pais finalizada'
        );
    }
}
