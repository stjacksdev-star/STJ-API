<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class SubscriberService
{
    public function index(?string $country = null, int $limit = 1000): array
    {
        $query = DB::table('stj_suscriptores')
            ->select(['id', 'email', 'fecha_suscripcion', 'pais'])
            ->orderByDesc('fecha_suscripcion')
            ->orderByDesc('id');

        if (filled($country) && strtoupper((string) $country) !== 'TODO') {
            $query->where('pais', strtoupper((string) $country));
        }

        $subscribers = $query
            ->limit(max(1, min($limit, 5000)))
            ->get()
            ->map(fn (object $subscriber) => $this->normalize($subscriber))
            ->values()
            ->all();

        return [
            'options' => [
                'countries' => $this->countries(),
            ],
            'subscribers' => $subscribers,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): array
    {
        $id = DB::table('stj_suscriptores')->insertGetId($this->payload($data));

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): array
    {
        $existing = DB::table('stj_suscriptores')->where('id', $id)->first();

        if (! $existing) {
            throw ValidationException::withMessages([
                'subscriber' => 'El suscriptor seleccionado no existe.',
            ]);
        }

        DB::table('stj_suscriptores')
            ->where('id', $id)
            ->update($this->payload($data, true));

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $deleted = DB::table('stj_suscriptores')->where('id', $id)->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'subscriber' => 'El suscriptor seleccionado no existe.',
            ]);
        }
    }

    private function find(int $id): array
    {
        $subscriber = DB::table('stj_suscriptores')->where('id', $id)->first();

        if (! $subscriber) {
            throw ValidationException::withMessages([
                'subscriber' => 'El suscriptor seleccionado no existe.',
            ]);
        }

        return $this->normalize($subscriber);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function countries(): array
    {
        $existing = DB::table('stj_suscriptores')
            ->whereNotNull('pais')
            ->where('pais', '<>', '')
            ->distinct()
            ->orderBy('pais')
            ->pluck('pais')
            ->map(fn (string $country) => strtoupper($country))
            ->all();

        $known = ['SV', 'GT', 'CR', 'NI', 'HN'];

        return collect([...$known, ...$existing])
            ->unique()
            ->values()
            ->map(fn (string $country) => [
                'code' => $country,
                'name' => $this->countryName($country),
            ])
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data, bool $updating = false): array
    {
        $payload = [
            'email' => strtolower(trim((string) $data['email'])),
            'pais' => strtoupper(trim((string) $data['country'])),
        ];

        if (! $updating) {
            $payload['fecha_suscripcion'] = $this->nullableDateTime($data['subscribedAt'] ?? null) ?? now();
        } elseif (array_key_exists('subscribedAt', $data)) {
            $payload['fecha_suscripcion'] = $this->nullableDateTime($data['subscribedAt']);
        }

        return $payload;
    }

    private function normalize(object $subscriber): array
    {
        return [
            'id' => (int) $subscriber->id,
            'email' => (string) $subscriber->email,
            'country' => strtoupper((string) $subscriber->pais),
            'countryName' => $this->countryName((string) $subscriber->pais),
            'subscribedAt' => $subscriber->fecha_suscripcion,
        ];
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    private function countryName(string $country): string
    {
        return match (strtoupper($country)) {
            'SV' => 'El Salvador',
            'GT' => 'Guatemala',
            'CR' => 'Costa Rica',
            'NI' => 'Nicaragua',
            'HN' => 'Honduras',
            default => strtoupper($country),
        };
    }
}
