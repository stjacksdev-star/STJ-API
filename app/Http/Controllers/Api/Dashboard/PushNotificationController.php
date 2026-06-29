<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\PushNotificationMaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushNotificationController extends BaseController
{
    public function __construct(
        private readonly PushNotificationMaintenanceService $pushNotifications,
    ) {}

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        if (! $this->canManagePushNotifications($request)) {
            return $this->error('No tienes permiso para administrar notificaciones push', 403);
        }

        $validated = $request->validate([
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(['TODO', 'PENDIENTE', 'ENVIADO', 'ERROR', 'CANCELADO'])],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'actor' => ['nullable', 'array'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.permissions.*' => ['nullable', 'string'],
        ]);

        return $this->success(
            $this->pushNotifications->index($validated),
            'Notificaciones push obtenidas'
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        if (! $this->canManagePushNotifications($request)) {
            return $this->error('No tienes permiso para administrar notificaciones push', 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:500'],
            'image' => ['nullable', 'image', 'max:5120'],
            'action' => ['required', 'string', 'max:500'],
            'to' => ['nullable', 'string', 'max:500'],
            'platform' => ['required', 'string', Rule::in(['Todo', 'Android', 'Ios'])],
            'scheduledAt' => ['required', 'date'],
            'promotionId' => ['nullable', 'integer', 'min:1'],
            'actor' => ['nullable', 'array'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.permissions.*' => ['nullable', 'string'],
        ]);

        return $this->success(
            $this->pushNotifications->create($validated, $request->file('image')),
            'Notificacion push programada correctamente'
        );
    }

    public function destroy(Request $request, int $notification)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        if (! $this->canManagePushNotifications($request)) {
            return $this->error('No tienes permiso para administrar notificaciones push', 403);
        }

        $request->validate([
            'actor' => ['nullable', 'array'],
            'actor.permissions' => ['nullable', 'array'],
            'actor.permissions.*' => ['nullable', 'string'],
        ]);

        return $this->success(
            $this->pushNotifications->cancel($notification),
            'Notificacion push cancelada correctamente'
        );
    }

    private function canManagePushNotifications(Request $request): bool
    {
        $permissions = collect((array) $request->input('actor.permissions', []))
            ->map(fn (mixed $permission) => strtoupper((string) $permission))
            ->all();

        return in_array('ROOT', $permissions, true)
            || in_array('MENU_PUSH_NOTIFICACIONES', $permissions, true)
            || in_array('OP_MENU_PUSH_NOTIFICACIONES', $permissions, true);
    }
}
