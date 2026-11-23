<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\AppointmentService;
use App\Services\Dashboard\Exceptions\StjCitasIntegrationException;
use Illuminate\Http\Request;

class AppointmentController extends BaseController
{
    public function __construct(
        private readonly AppointmentService $appointments,
    ) {}

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

        try {
            return $this->success(
                $this->appointments->appointments($validated),
                'Citas obtenidas'
            );
        } catch (StjCitasIntegrationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'error' => $exception->errorCode,
            ], $exception->httpStatus);
        }
    }

    public function export(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['required', 'string', 'max:3'],
            'store' => ['required', 'string', 'max:20'],
        ]);

        try {
            $export = $this->appointments->export($validated);

            return response($export['contents'], 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        } catch (StjCitasIntegrationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'error' => $exception->errorCode,
            ], $exception->httpStatus);
        }
    }
}
