<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\ClaimService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClaimController extends BaseController
{
    public function __construct(
        private readonly ClaimService $claims,
    ) {
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(ClaimService::STATUSES)],
            'type' => ['nullable', Rule::in(ClaimService::TYPES)],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->success(
            $this->claims->index($validated),
            'Reclamos obtenidos'
        );
    }

    public function export(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(ClaimService::STATUSES)],
            'type' => ['nullable', Rule::in(ClaimService::TYPES)],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
        ]);

        $export = $this->claims->export($validated);

        return response($export['contents'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $this->validateClaim($request);

        return $this->success(
            $this->claims->create($validated),
            'Reclamo creado correctamente'
        );
    }

    public function update(Request $request, int $claim)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $this->validateClaim($request, $claim);

        return $this->success(
            $this->claims->update($claim, $validated),
            'Reclamo actualizado correctamente'
        );
    }

    public function destroy(Request $request, int $claim)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $this->claims->delete($claim);

        return $this->success([], 'Reclamo eliminado correctamente');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateClaim(Request $request, ?int $claim = null): array
    {
        return $request->validate([
            'managementNumber' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('stj_reclamos', 'rec_numero_gestion')->ignore($claim, 'rec_id'),
            ],
            'country' => ['required', 'integer', 'min:1'],
            'registeredAt' => ['nullable', 'date'],
            'orderId' => ['nullable', 'integer', 'min:1'],
            'stj' => ['nullable', 'string', 'max:50'],
            'customerName' => ['required', 'string', 'max:150'],
            'customerEmail' => ['nullable', 'email', 'max:150'],
            'customerPhone' => ['nullable', 'string', 'max:30'],
            'customerDui' => ['nullable', 'string', 'max:20'],
            'type' => ['required', Rule::in(ClaimService::TYPES)],
            'typeOther' => ['nullable', 'required_if:type,otro,otros', 'string', 'max:255'],
            'origin' => ['required', Rule::in(ClaimService::ORIGINS)],
            'originOther' => ['nullable', 'required_if:origin,otro,otros', 'string', 'max:255'],
            'responsibleArea' => ['required', Rule::in(ClaimService::AREAS)],
            'store' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'response' => ['nullable', 'string'],
            'status' => ['required', Rule::in(ClaimService::STATUSES)],
            'rejectionReason' => ['nullable', 'string'],
            'resolvedAt' => ['nullable', 'date'],
            'closedAt' => ['nullable', 'date'],
            'registeredBy' => ['nullable', 'string', 'max:255'],
            'assignedTo' => ['nullable', 'string', 'max:255'],
            'actor' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'max:8192'],
        ]);
    }
}
