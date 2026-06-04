<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ProductMasterService;
use Illuminate\Http\Request;

class ProductMasterController extends BaseController
{
    public function __construct(
        private readonly ProductMasterService $products,
    ) {
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->products->index(
                $request->string('search')->toString(),
                $request->integer('limit', 300),
            ),
            'Productos obtenidos'
        );
    }

    public function import(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        return $this->success(
            $this->products->import($validated['file']),
            'Importacion de productos finalizada'
        );
    }

    public function show(Request $request, int $product)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->products->detail($product),
            'Detalle de producto obtenido'
        );
    }

    public function photos(Request $request, int $product)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->products->photos($product),
            'Fotografias de producto obtenidas'
        );
    }

    public function countries(Request $request, int $product)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->products->countries($product),
            'Paises de producto obtenidos'
        );
    }

    public function importPhotos(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        @set_time_limit(900);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480'],
        ]);

        return $this->success(
            $this->products->importPhotos($validated['file']),
            'Importacion de fotografias finalizada'
        );
    }
}
