<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\UserCountryAccessService;
use Illuminate\Http\Request;

class UserCountryAccessController extends BaseController
{
    public function __construct(
        private readonly UserCountryAccessService $access,
    ) {
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->actorRules());

        return $this->success(
            $this->access->index($validated['actor'] ?? []),
            'Asignaciones de paises obtenidas'
        );
    }

    public function current(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate($this->actorRules());

        return $this->success(
            $this->access->current($validated['actor'] ?? []),
            'Paises permitidos obtenidos'
        );
    }

    public function users(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success(
            ['users' => $this->access->users($validated['search'] ?? null, (int) ($validated['limit'] ?? 50))],
            'Usuarios obtenidos'
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'casUserId' => ['required', 'integer', 'min:1'],
            'username' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:180'],
            'countries' => ['required', 'array', 'min:1'],
            'countries.*.id' => ['required', 'integer', 'min:1'],
            'countries.*.code' => ['required', 'string', 'max:5'],
            'countries.*.name' => ['nullable', 'string', 'max:120'],
            'defaultCountryId' => ['nullable', 'integer', 'min:1'],
            ...$this->actorRules(),
        ]);

        $this->access->save($validated);

        return $this->success([], 'Asignacion de paises guardada correctamente');
    }

    public function destroy(Request $request, int $assignment)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $this->access->delete($assignment);

        return $this->success([], 'Pais removido del usuario correctamente');
    }

    /**
     * @return array<string, mixed>
     */
    private function actorRules(): array
    {
        return [
            'actor' => ['nullable', 'array'],
            'actor.id' => ['nullable'],
            'actor.name' => ['nullable', 'string', 'max:180'],
            'actor.email' => ['nullable', 'string', 'max:255'],
            'actor.username' => ['nullable', 'string', 'max:120'],
            'actor.countryId' => ['nullable'],
            'actor.countryCode' => ['nullable', 'string', 'max:5'],
            'actor.ip' => ['nullable', 'string', 'max:45'],
            'actor.userAgent' => ['nullable', 'string', 'max:500'],
        ];
    }
}
