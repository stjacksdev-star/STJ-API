<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\StandaloneAssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StandaloneAssetController extends BaseController
{
    public function __construct(private readonly StandaloneAssetService $assets) {}

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) return $this->error('Token sin permiso dashboard', 403);
        return $this->success($this->assets->index(), 'Assets obtenidos');
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) return $this->error('Token sin permiso dashboard', 403);
        $data = $request->validate($this->rules(true));
        return $this->success($this->assets->create($data, $request->file('image'), $request->file('mobileImage')), 'Asset creado correctamente');
    }

    public function update(Request $request, int $asset)
    {
        if (! $request->user()?->tokenCan('dashboard')) return $this->error('Token sin permiso dashboard', 403);
        $data = $request->validate($this->rules(false));
        return $this->success($this->assets->update($asset, $data, $request->file('image'), $request->file('mobileImage')), 'Asset actualizado correctamente');
    }

    private function rules(bool $imageRequired): array
    {
        return [
            'countryId' => ['required', 'integer'], 'type' => ['required', Rule::in(['CUPON', 'LO-MAS-NUEVO', 'BANNER', 'MODAL', 'SLIDER'])],
            'platform' => ['nullable', Rule::in(['TODO', 'WEB', 'APP'])], 'position' => ['nullable', Rule::in(['DERECHA', 'IZQUIERDA', 'CENTRO'])],
            'order' => ['nullable', 'integer', 'min:0'], 'status' => ['nullable', Rule::in(['ACTIVO', 'PENDIENTE', 'CANCELADO', 'FINALIZADO'])],
            'startAt' => ['required', 'date'], 'endAt' => ['required', 'date', 'after_or_equal:startAt'],
            'link' => ['nullable', 'string', 'max:1000'], 'title' => ['nullable', 'string', 'max:255'],
            'image' => [$imageRequired ? 'required' : 'nullable', 'image', 'max:5120'], 'mobileImage' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
