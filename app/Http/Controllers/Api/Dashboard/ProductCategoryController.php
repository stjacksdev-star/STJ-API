<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ProductCategoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCategoryController extends BaseController
{
    public function __construct(
        private readonly ProductCategoryService $categories,
    ) {
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->categories->index($request->integer('limit', 500)),
            'Categorias obtenidas'
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $this->validateCategory($request);

        return $this->success(
            $this->categories->create($validated),
            'Categoria creada correctamente'
        );
    }

    public function update(Request $request, int $category)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $this->validateCategory($request, $category);

        return $this->success(
            $this->categories->update($category, $validated),
            'Categoria actualizada correctamente'
        );
    }

    public function destroy(Request $request, int $category)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $this->categories->delete($category);

        return $this->success([], 'Categoria eliminada correctamente');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCategory(Request $request, ?int $category = null): array
    {
        return $request->validate([
            'order' => ['nullable', 'integer', 'min:0'],
            'appOrder' => ['nullable', 'integer', 'min:0'],
            'code' => [
                'required',
                'string',
                'max:25',
                Rule::unique('stj_categorias', 'cat_codigo')->ignore($category, 'cat_id'),
            ],
            'name' => ['required', 'string', 'max:100'],
            'align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'header' => ['nullable', 'string', 'max:100'],
            'logo' => ['required', 'string', 'max:100'],
            'appName' => ['nullable', 'string', 'max:100'],
            'appLogo' => ['required', 'string', 'max:100'],
            'hasOtherSubcategories' => ['nullable', 'boolean'],
            'otherSubcategories' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
            'sizes' => ['required', 'string', 'max:250'],
            'brand' => ['nullable', Rule::in(['ST JACKS', 'BUNGEE', 'BASICS', 'BASIKOS', 'JACK & CO'])],
            'enabledSv' => ['nullable', 'boolean'],
            'enabledGt' => ['nullable', 'boolean'],
            'enabledCr' => ['nullable', 'boolean'],
            'enabledNi' => ['nullable', 'boolean'],
            'enabledApp' => ['nullable', 'boolean'],
            'actor' => ['nullable', 'array'],
        ]);
    }
}
