<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\CollectionAssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CollectionAssetController extends BaseController
{
    public function __construct(
        private readonly CollectionAssetService $assets,
    ) {
    }

    public function index(Request $request, int $collection)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->assets->index($collection),
            'Assets de coleccion obtenidos'
        );
    }

    public function store(Request $request, int $collection)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->rules(imageRequired: true));

        return $this->success(
            $this->assets->create(
                $collection,
                $validated,
                $request->file('image'),
                $request->file('mobileImage'),
            ),
            'Asset de coleccion creado correctamente'
        );
    }

    public function update(Request $request, int $asset)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->rules(imageRequired: false));

        return $this->success(
            $this->assets->update(
                $asset,
                $validated,
                $request->file('image'),
                $request->file('mobileImage'),
            ),
            'Asset actualizado correctamente'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $imageRequired): array
    {
        return [
            'type' => ['required', Rule::in(['CUPON', 'LO-MAS-NUEVO', 'BANNER', 'MODAL', 'SLIDER'])],
            'platform' => ['nullable', Rule::in(['TODO', 'WEB', 'APP'])],
            'position' => ['nullable', Rule::in(['DERECHA', 'IZQUIERDA', 'CENTRO'])],
            'order' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in(['ACTIVO', 'PENDIENTE', 'CANCELADO', 'FINALIZADO'])],
            'startAt' => ['required', 'date'],
            'endAt' => ['required', 'date', 'after_or_equal:startAt'],
            'title' => ['nullable', 'string', 'max:45'],
            'actionType' => ['nullable', 'integer'],
            'promotionId' => ['nullable', 'integer'],
            'image' => [$imageRequired ? 'required' : 'nullable', 'image', 'max:5120'],
            'mobileImage' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
