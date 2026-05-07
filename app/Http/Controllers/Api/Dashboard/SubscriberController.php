<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Api\BaseController;
use App\Services\Dashboard\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriberController extends BaseController
{
    public function __construct(
        private readonly SubscriberService $subscribers,
    ) {
    }

    public function index(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $validated = $request->validate([
            'country' => ['nullable', 'string', 'max:5'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        return $this->success(
            $this->subscribers->index($validated['country'] ?? null, (int) ($validated['limit'] ?? 1000)),
            'Suscriptores obtenidos'
        );
    }

    public function store(Request $request)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->subscribers->create($this->validateSubscriber($request)),
            'Suscriptor creado correctamente'
        );
    }

    public function update(Request $request, int $subscriber)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        return $this->success(
            $this->subscribers->update($subscriber, $this->validateSubscriber($request, $subscriber)),
            'Suscriptor actualizado correctamente'
        );
    }

    public function destroy(Request $request, int $subscriber)
    {
        if (! $request->user()?->tokenCan('dashboard')) {
            return $this->error('Token sin permiso dashboard', 403);
        }

        $this->subscribers->delete($subscriber);

        return $this->success([], 'Suscriptor eliminado correctamente');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSubscriber(Request $request, ?int $subscriber = null): array
    {
        return $request->validate([
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('stj_suscriptores', 'email')->ignore($subscriber, 'id'),
            ],
            'country' => ['required', 'string', 'max:5'],
            'subscribedAt' => ['nullable', 'date'],
        ]);
    }
}
