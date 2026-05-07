<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\AppointmentService;
use Illuminate\Http\Request;

class AppointmentController extends BaseController
{
    public function __construct(
        private readonly AppointmentService $appointments,
    ) {
    }

    public function catalog(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['nullable', 'string', 'max:3'],
        ]);

        return $this->success(
            $this->appointments->catalog($validated['country'] ?? null),
            'Catalogo de citas obtenido'
        );
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['required', 'string', 'max:20'],
        ]);

        return $this->success(
            $this->appointments->appointments($validated),
            'Citas obtenidas'
        );
    }
}
