<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\PromotionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends BaseController
{
    public function __construct(
        private readonly PromotionService $promotions,
    ) {}

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->promotions->index(
                $request->string('country')->toString(),
                $request->string('status')->toString(),
                $request->integer('limit', 200),
            ),
            'Promociones del dashboard obtenidas'
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
            'commercialName' => ['nullable', 'string', 'max:255'],
            'origin' => ['required', Rule::in(['TODO', 'WEB', 'APP'])],
            'checkoutType' => ['nullable', Rule::in(['TODO', 'D', 'T'])],
            'type' => ['required', Rule::in(['TODO', 'SKU'])],
            'promotionType' => ['required', Rule::in(['DESCUENTO', 'CONDICION-SKU', 'PUNTO-PRECIO', 'DESCUENTO-SKU'])],
            'restriction' => ['nullable', Rule::in(['21/2', '2x1', '2doPrecio', '2xPP'])],
            'price' => ['nullable', 'numeric', 'min:0'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'startAt' => ['required', 'date'],
            'endAt' => ['required', 'date', 'after:startAt'],
            'products' => ['nullable', 'file', 'max:5120'],
            ...$this->actorRules(),
        ]);

        return $this->success(
            $this->promotions->create($validated, $request->file('products'), $validated['actor'] ?? []),
            'Promocion creada correctamente'
        );
    }

    public function updateSchedule(Request $request, int $promotion)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'commercialName' => ['nullable', 'string', 'max:255'],
            'startAt' => ['nullable', 'date'],
            'endAt' => ['nullable', 'date'],
            ...$this->actorRules(),
        ]);

        return $this->success(
            $this->promotions->updateSchedule($promotion, $validated, $validated['actor'] ?? []),
            'Horario de promocion actualizado correctamente'
        );
    }

    public function replaceProducts(Request $request, int $promotion)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'products' => ['required', 'file', 'max:5120'],
            ...$this->actorRules(),
        ]);

        return $this->success(
            $this->promotions->replaceProducts($promotion, $request->file('products'), $validated['actor'] ?? []),
            'Productos de la promocion reemplazados correctamente'
        );
    }

    private function actorRules(): array
    {
        return [
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:150'],
            'actor.email' => ['nullable', 'string', 'max:255'],
            'actor.username' => ['nullable', 'string', 'max:120'],
        ];
    }
}
