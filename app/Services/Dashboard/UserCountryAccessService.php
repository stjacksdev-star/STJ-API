<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class UserCountryAccessService
{
    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function index(array $actor): array
    {
        return [
            'assignments' => DB::table('dashboard_user_country_access')
                ->orderBy('name')
                ->orderBy('username')
                ->orderBy('cas_user_id')
                ->orderByDesc('is_default')
                ->orderBy('country_name')
                ->get()
                ->groupBy('cas_user_id')
                ->map(fn ($rows) => $this->assignmentGroup($rows))
                ->values()
                ->all(),
            'countries' => $this->countries(),
            'currentUser' => [
                'casUserId' => $this->actorUserId($actor),
                'username' => $actor['username'] ?? null,
                'email' => $actor['email'] ?? null,
                'name' => $actor['name'] ?? null,
                'baseCountry' => $this->baseCountry($actor),
                'allowedCountries' => $this->allowedCountries($actor),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function current(array $actor): array
    {
        return [
            'baseCountry' => $this->baseCountry($actor),
            'allowedCountries' => $this->allowedCountries($actor),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function users(?string $search = null, int $limit = 50): array
    {
        $search = mb_strtolower(trim((string) $search));
        $limit = max(1, min($limit, 100));

        $response = Http::timeout(20)
            ->acceptJson()
            ->get('https://backend.stjacks.com/Corporativo/Usuarios/getTodo');

        $response->throw();

        return collect($response->json('db') ?? [])
            ->map(fn (array $user) => [
                'id' => (int) ($user['usu_id'] ?? 0),
                'employeeCode' => (string) ($user['usu_codigo_empleado'] ?? ''),
                'status' => (string) ($user['usu_estado'] ?? ''),
                'name' => trim((string) ($user['usu_nombre'] ?? '')),
                'email' => strtolower(trim((string) ($user['usu_correo'] ?? ''))),
                'departmentId' => (int) ($user['dep_id'] ?? 0),
                'department' => trim((string) ($user['dep_nombre'] ?? '')),
                'systems' => trim((string) ($user['sistemas'] ?? '')),
            ])
            ->filter(fn (array $user) => $user['id'] > 0)
            ->filter(function (array $user) use ($search) {
                if ($search === '') {
                    return strtoupper($user['status']) === 'ACTIVO';
                }

                return str_contains(mb_strtolower($user['name']), $search)
                    || str_contains(mb_strtolower($user['email']), $search)
                    || str_contains((string) $user['id'], $search);
            })
            ->sortBy('name')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        $casUserId = (int) $data['casUserId'];
        $countries = collect($data['countries'] ?? [])
            ->map(fn (array $country) => [
                'id' => (int) $country['id'],
                'code' => strtoupper((string) $country['code']),
                'name' => $country['name'] ?? null,
            ])
            ->unique('id')
            ->values();

        if ($countries->isEmpty()) {
            throw ValidationException::withMessages([
                'countries' => 'Debe seleccionar al menos un pais.',
            ]);
        }

        $defaultCountryId = (int) ($data['defaultCountryId'] ?: $countries->first()['id']);
        $actorId = $this->actorUserId($data['actor'] ?? []);

        DB::transaction(function () use ($data, $casUserId, $countries, $defaultCountryId, $actorId) {
            DB::table('dashboard_user_country_access')
                ->where('cas_user_id', $casUserId)
                ->whereNotIn('country_id', $countries->pluck('id')->all())
                ->delete();

            foreach ($countries as $country) {
                DB::table('dashboard_user_country_access')->updateOrInsert(
                    [
                        'cas_user_id' => $casUserId,
                        'country_id' => $country['id'],
                    ],
                    [
                        'username' => $data['username'] ?? null,
                        'email' => $data['email'] ?? null,
                        'name' => $data['name'] ?? null,
                        'country_code' => $country['code'],
                        'country_name' => $country['name'],
                        'is_default' => $country['id'] === $defaultCountryId,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });
    }

    public function delete(int $assignment): void
    {
        $deleted = DB::table('dashboard_user_country_access')
            ->where('id', $assignment)
            ->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'assignment' => 'La asignacion seleccionada no existe.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $actor
     * @return array<int, array<string, mixed>>
     */
    public function allowedCountries(array $actor): array
    {
        $countries = collect();
        $base = $this->baseCountry($actor);

        if ($base !== null) {
            $countries->push($base);
        }

        $casUserId = $this->actorUserId($actor);

        if ($casUserId !== null) {
            $countries = $countries->merge(
                DB::table('dashboard_user_country_access')
                    ->where('cas_user_id', $casUserId)
                    ->orderByDesc('is_default')
                    ->orderBy('country_name')
                    ->get()
                    ->map(fn (object $row) => [
                        'id' => (int) $row->country_id,
                        'code' => strtoupper((string) $row->country_code),
                        'name' => $row->country_name ?: strtoupper((string) $row->country_code),
                        'isDefault' => (bool) $row->is_default,
                        'source' => 'assignment',
                    ])
            );
        }

        return $countries
            ->unique(fn (array $country) => (int) $country['id'])
            ->sortByDesc(fn (array $country) => (bool) ($country['isDefault'] ?? false))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function countries(): array
    {
        return DB::table('stj_paises')
            ->select(['pai_id', 'pai_codigo', 'pai_nombre'])
            ->orderBy('pai_id')
            ->get()
            ->map(fn (object $country) => [
                'id' => (int) $country->pai_id,
                'code' => strtoupper((string) $country->pai_codigo),
                'name' => trim((string) $country->pai_nombre),
            ])
            ->values()
            ->all();
    }

    private function assignmentGroup($rows): array
    {
        $first = $rows->first();

        return [
            'casUserId' => (int) $first->cas_user_id,
            'username' => $first->username,
            'email' => $first->email,
            'name' => $first->name,
            'countries' => $rows
                ->map(fn (object $row) => [
                    'assignmentId' => (int) $row->id,
                    'id' => (int) $row->country_id,
                    'code' => strtoupper((string) $row->country_code),
                    'name' => $row->country_name,
                    'isDefault' => (bool) $row->is_default,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function baseCountry(array $actor): ?array
    {
        $id = $actor['countryId'] ?? null;
        $code = strtoupper((string) ($actor['countryCode'] ?? ''));

        if (! is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        return [
            'id' => (int) $id,
            'code' => $code !== '' ? $code : (string) $id,
            'name' => $code !== '' ? $code : 'Pais '.$id,
            'isDefault' => true,
            'source' => 'session',
        ];
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function actorUserId(array $actor): ?int
    {
        $id = $actor['id'] ?? $actor['casUserId'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
