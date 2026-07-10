<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StorefrontSubscriberService
{
    /**
     * @return array<string, mixed>
     */
    public function subscribe(string $country, string $email): array
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedCountry = strtoupper(trim($country));

        $existing = DB::table('stj_suscriptores')
            ->where('email', $normalizedEmail)
            ->first();

        if ($existing) {
            return [
                'ok' => false,
                'status' => 409,
                'message' => 'El correo ya esta registrado.',
                'subscriber' => [
                    'email' => $normalizedEmail,
                    'country' => strtoupper((string) $existing->pais),
                ],
            ];
        }

        try {
            $id = DB::table('stj_suscriptores')->insertGetId([
                'email' => $normalizedEmail,
                'pais' => $normalizedCountry,
                'fecha_suscripcion' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            return [
                'ok' => false,
                'status' => 409,
                'message' => 'El correo ya esta registrado.',
                'subscriber' => [
                    'email' => $normalizedEmail,
                    'country' => $normalizedCountry,
                ],
            ];
        }

        return [
            'ok' => true,
            'status' => 201,
            'message' => 'Gracias por suscribirte.',
            'subscriber' => [
                'id' => $id,
                'email' => $normalizedEmail,
                'country' => $normalizedCountry,
            ],
        ];
    }
}
