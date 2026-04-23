<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\PromotionAssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionAssetController extends BaseController
{
    public function __construct(
        private readonly PromotionAssetService $assets,
    ) {
    }

    public function index(Request $request, int $promotion)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->assets->index($promotion),
            'Assets de promocion obtenidos'
        );
    }

    public function store(Request $request, int $promotion)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->rules(imageRequired: true));

        return $this->success(
            $this->assets->create(
                $promotion,
                $validated,
                $request->file('image'),
                $request->file('mobileImage'),
            ),
            'Asset de promocion creado correctamente'
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
            'Asset de promocion actualizado correctamente'
        );
    }

    public function destroy(Request $request, int $asset)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $this->assets->delete($asset);

        return $this->success([], 'Asset de promocion eliminado correctamente');
    }

    public function updateHeader(Request $request, int $promotion)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $request->validate([
            'header' => ['required', 'image', 'max:5120'],
        ]);

        return $this->success(
            $this->assets->updateHeader($promotion, $request->file('header')),
            'Banner de promocion actualizado correctamente'
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
            'image' => [$imageRequired ? 'required' : 'nullable', 'image', 'max:5120'],
            'mobileImage' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
