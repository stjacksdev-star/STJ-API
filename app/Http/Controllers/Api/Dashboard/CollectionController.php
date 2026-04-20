<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\CollectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectionController extends BaseController
{
    public function __construct(
        private readonly CollectionService $collections,
    ) {
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->collections->index(
                $request->string('country')->toString(),
                $request->integer('limit', 200),
            ),
            'Colecciones del dashboard obtenidas'
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'name' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:100'],
            'mobilePosition' => ['nullable', Rule::in(['left', 'right', 'center'])],
            'banner' => ['required', 'image', 'max:5120'],
            'products' => ['required', 'file', 'max:5120'],
        ]);

        return $this->success(
            $this->collections->create(
                $validated,
                $request->file('banner'),
                $request->file('products'),
            ),
            'Coleccion creada correctamente'
        );
    }

    public function update(Request $request, int $collection)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'name' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:100'],
            'mobilePosition' => ['nullable', Rule::in(['left', 'right', 'center'])],
            'banner' => ['nullable', 'image', 'max:5120'],
            'products' => ['nullable', 'file', 'max:5120'],
        ]);

        return $this->success(
            $this->collections->update(
                $collection,
                $validated,
                $request->file('banner'),
                $request->file('products'),
            ),
            'Coleccion actualizada correctamente'
        );
    }
}
