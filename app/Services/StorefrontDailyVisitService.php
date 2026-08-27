<?php

namespace App\Services;

use App\Models\StorefrontCustomer;
use App\Models\StorefrontVisitor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontDailyVisitService
{
    /**
     * @return array{created: bool, date: string, country: string, origin: string}
     */
    public function record(
        string $countryCode,
        StorefrontVisitor $visitor,
        ?StorefrontCustomer $customer = null,
        string $origin = 'WEB',
    ): array {
        $countryCode = strtoupper(trim($countryCode));
        $origin = strtoupper(trim($origin));
        $countryId = DB::table('stj_paises')
            ->where('pai_codigo', $countryCode)
            ->value('pai_id');

        if ($countryId === null) {
            throw ValidationException::withMessages([
                'country' => 'El pais indicado no existe.',
            ]);
        }

        $now = now();
        $date = $now->toDateString();
        $identity = [
            'vdi_fecha' => $date,
            'vdi_visitante_id' => $visitor->getKey(),
            'vdi_pais_id' => (int) $countryId,
            'vdi_origen' => $origin,
        ];

        try {
            DB::table('stj_visitas_diarias')->insert([
                ...$identity,
                'vdi_usuario_id' => $customer?->getKey(),
                'vdi_primera_hora' => $now,
                'vdi_ultima_hora' => $now,
                'vdi_creado_en' => $now,
                'vdi_actualizado_en' => $now,
            ]);
            $created = true;
        } catch (UniqueConstraintViolationException) {
            $created = false;
        }

        $updates = [
            'vdi_ultima_hora' => $now,
            'vdi_actualizado_en' => $now,
        ];

        if ($customer) {
            $updates['vdi_usuario_id'] = $customer->getKey();
        }

        DB::table('stj_visitas_diarias')
            ->where($identity)
            ->update($updates);

        return [
            'created' => $created,
            'date' => $date,
            'country' => $countryCode,
            'origin' => $origin,
        ];
    }
}
