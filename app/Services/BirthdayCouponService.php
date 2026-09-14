<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BirthdayCouponService
{
    private const TEMPLATE = 'CUMPLE';

    private const VALIDITY_DAYS = 15;

    /** @return array{customers:int,generated:int,withoutTemplate:int,duplicates:int} */
    public function generateToday(): array
    {
        $now = now();
        $templates = $this->templates($now);
        $customers = DB::table('stj_usuarios')
            ->where('usu_activo', 1)
            ->whereNotNull('usu_fecha_nacimiento')
            ->whereNotIn('usu_fecha_nacimiento', ['', '1969-12-31'])
            ->whereNotNull('usu_pais_registro')
            ->where('usu_pais_registro', '>', 0)
            ->whereMonth('usu_fecha_nacimiento', $now->month)
            ->whereDay('usu_fecha_nacimiento', $now->day)
            ->get(['usu_id', 'usu_nombre', 'usu_usuario', 'usu_correo', 'usu_tipo_login', 'usu_pais_registro']);

        $summary = ['customers' => $customers->count(), 'generated' => 0, 'withoutTemplate' => 0, 'duplicates' => 0];
        foreach ($customers as $customer) {
            $email = strtolower(trim((string) ($customer->usu_correo ?: $customer->usu_usuario)));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $summary['withoutTemplate']++;
                continue;
            }

            $channel = strtoupper(trim((string) $customer->usu_tipo_login)) === 'APP' ? 'APP' : 'WEB';
            $template = $this->templateFor($templates, (int) $customer->usu_pais_registro, $channel);
            if (! $template) {
                $summary['withoutTemplate']++;
                continue;
            }

            DB::transaction(function () use ($customer, $email, $template, $now, &$summary) {
                if ($this->alreadyIssued($email, (int) $customer->usu_pais_registro, $now)) {
                    $summary['duplicates']++;
                    return;
                }

                $header = (array) $template;
                unset($header['che_id'], $header['pai_codigo']);
                $header['che_nombre'] = self::TEMPLATE;
                $header['che_nombre_comercial'] = trim((string) ($template->che_nombre_comercial ?: 'FELIZ CUMPLE'));
                $header['che_generico'] = 'NO';
                $header['che_regional'] = 'NO';
                $header['che_pais'] = (int) $customer->usu_pais_registro;
                $header['che_inicio'] = $now;
                $header['che_final'] = $now->copy()->addDays(self::VALIDITY_DAYS);
                $header['che_config_automatica'] = null;
                $header['che_para'] = self::TEMPLATE;
                $header['che_estado'] = 'ACTIVO';

                $headerId = DB::table('stj_cupones_header')->insertGetId($header);
                DB::table('stj_cupones')->insert([
                    'cup_header' => $headerId,
                    'cup_codigo' => $this->uniqueCode($now),
                    'cup_estado' => 'ACTIVO',
                    'cup_fecha' => $now,
                    'cup_vigencia' => self::VALIDITY_DAYS,
                    'cup_monto' => $template->che_monto,
                    'cup_descuento' => $template->che_descuento,
                    'cup_multiple' => $template->che_multiple ?: 'NO',
                    'cup_disponible' => $template->che_monto,
                    'cup_pais' => strtoupper((string) $template->pai_codigo),
                    'cup_aplica_monto_minimo' => $template->che_aplica_monto_minimo,
                    'cup_monto_minimo' => $template->che_monto_minimo,
                    'cup_correo' => $email,
                    'cup_correo_enviado' => 0,
                ]);

                if (Schema::hasTable('stj_cupones_producto')) {
                    foreach (DB::table('stj_cupones_producto')->where('cpr_cupon', $template->che_id)->get() as $product) {
                        $copy = (array) $product;
                        unset($copy['cpr_id']);
                        $copy['cpr_cupon'] = $headerId;
                        DB::table('stj_cupones_producto')->insert($copy);
                    }
                }
                $summary['generated']++;
            });
        }

        return $summary;
    }

    private function templates(Carbon $now): Collection
    {
        return DB::table('stj_cupones_header as h')
            ->join('stj_paises as p', 'p.pai_id', '=', 'h.che_pais')
            ->where('h.che_config_automatica', self::TEMPLATE)
            ->where('h.che_estado', 'ACTIVO')
            ->where('h.che_inicio', '<=', $now)
            ->where('h.che_final', '>=', $now)
            ->whereIn('h.che_aplica', ['WEB', 'APP', 'TODO'])
            ->orderByDesc('h.che_inicio')
            ->orderByDesc('h.che_id')
            ->get(['h.*', 'p.pai_codigo']);
    }

    private function templateFor(Collection $templates, int $countryId, string $channel): ?object
    {
        return $templates
            ->where('che_pais', $countryId)
            ->filter(fn (object $template) => in_array(strtoupper((string) $template->che_aplica), [$channel, 'TODO'], true))
            ->sortByDesc(fn (object $template) => (strtoupper((string) $template->che_aplica) === $channel ? '1' : '0').'|'.$template->che_inicio.'|'.str_pad((string) $template->che_id, 12, '0', STR_PAD_LEFT))
            ->first();
    }

    private function alreadyIssued(string $email, int $countryId, Carbon $now): bool
    {
        return DB::table('stj_cupones as c')
            ->join('stj_cupones_header as h', 'h.che_id', '=', 'c.cup_header')
            ->whereRaw('LOWER(TRIM(c.cup_correo)) = ?', [$email])
            ->where('h.che_pais', $countryId)
            ->where('h.che_para', self::TEMPLATE)
            ->whereYear('h.che_inicio', $now->year)
            ->exists();
    }

    private function uniqueCode(Carbon $now): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'CUMPLE'.$now->format('md').'-'.strtoupper(bin2hex(random_bytes(2)));
            if (! DB::table('stj_cupones')->where('cup_codigo', $code)->exists()) return $code;
        }
        throw new RuntimeException('No fue posible generar un código único para el cupón de cumpleaños.');
    }
}
