<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontShippingService
{
    public function quote(object $country, string $fulfillmentType, ?int $cityId, string $subtotal, ?CarbonInterface $at = null): array
    {
        $type = strtoupper(trim($fulfillmentType));
        $currency = $this->currency((string) $country->pai_codigo);
        if ($type === 'TIENDA') {
            return $this->result(0, $currency, 'STORE_PICKUP', null, 0, 0, 'Retiro en tienda sin costo.');
        }
        if ($type !== 'DOMICILIO') {
            throw ValidationException::withMessages(['fulfillment' => 'El tipo de entrega no es valido.']);
        }
        if (! $cityId) {
            throw ValidationException::withMessages(['delivery.city_id' => 'Selecciona una ciudad para calcular el envio.']);
        }

        $city = DB::table('stj_world_cities as city')
            ->join('stj_world_states as state', 'state.id', '=', 'city.state_id')
            ->where('city.id', $cityId)
            ->where('city.country_id', $country->pai_id_world)
            ->where('state.country_id', $country->pai_id_world)
            ->first(['city.id', 'city.name', 'city.costo', 'city.envio_disponible', 'city.id_urbano', 'state.id as state_id', 'state.name as state_name']);
        if (! $city) {
            throw ValidationException::withMessages(['delivery.city_id' => 'La ciudad no pertenece al pais activo.']);
        }
        $countryRate = DB::table('stj_envio_pais')->where('envio_pais', $country->pai_id)->where('envio_estado', 'ACTIVO')->orderByDesc('envio_id')->first();
        if (! $countryRate) {
            throw ValidationException::withMessages(['shipping' => 'No existe una tarifa de envio activa para el pais.']);
        }
        $subtotalCents = $this->cents($subtotal);
        $countryCents = $this->cents((string) $countryRate->envio_valor);
        $cityCents = $this->cents((string) ($city->costo ?? '0'));
        if ($countryCents < 0 || $cityCents < 0) {
            throw ValidationException::withMessages(['shipping' => 'La configuracion de envio contiene un importe invalido.']);
        }
        $baseCents = $cityCents > 0 ? $cityCents : $countryCents;
        $source = $cityCents > 0 ? 'CITY_RATE' : 'COUNTRY_RATE';
        $now = $at ?? now();
        $rule = DB::table('stj_envio_reglas')->where('enr_pais', $country->pai_id)->where('enr_estado', 'ACTIVO')
            ->where('enr_fecha_inicio', '<=', $now)->where('enr_fecha_fin', '>=', $now)
            ->orderBy('enr_prioridad')->orderByDesc('enr_id')->first();
        $minimumCents = $rule ? $this->cents((string) $rule->enr_monto_minimo) : 0;
        $remainingCents = max(0, $minimumCents - $subtotalCents);
        if ($rule && $subtotalCents >= $minimumCents) {
            if ($rule->enr_tipo === 'ENVIO_GRATIS') {
                return $this->result(0, $currency, 'FREE_RULE', (int) $rule->enr_id, $minimumCents, 0, 'Tu pedido aplica para envio gratis.', $city);
            }
            if ($rule->enr_tipo === 'ENVIO_FIJO') {
                $fixed = $this->cents((string) $rule->enr_valor_envio);
                if ($fixed < 0) throw ValidationException::withMessages(['shipping' => 'La regla de envio contiene un importe invalido.']);
                return $this->result($fixed, $currency, 'FREE_RULE', (int) $rule->enr_id, $minimumCents, 0, $fixed === 0 ? 'Tu pedido aplica para envio gratis.' : 'Se aplico una tarifa promocional de envio.', $city);
            }
        }

        $message = $remainingCents > 0
            ? 'Agrega '.$this->money($remainingCents, (string) $countryRate->envio_moneda_simbolo).' mas para aplicar a envio gratis.'
            : 'Costo de envio: '.$this->money($baseCents, (string) $countryRate->envio_moneda_simbolo).'.';
        return $this->result($baseCents, $currency, $source, null, $minimumCents, $remainingCents, $message, $city);
    }

    private function result(int $amount, string $currency, string $source, ?int $ruleId, int $minimum, int $remaining, string $message, ?object $city = null): array
    {
        $symbol = ['USD' => '$', 'GTQ' => 'Q', 'CRC' => 'C', 'HNL' => 'L'][$currency] ?? $currency.' ';
        return ['shipping_amount' => $this->decimal($amount), 'display_amount' => $amount === 0 ? 'GRATIS' : $symbol.$this->decimal($amount), 'currency' => $currency, 'currency_symbol' => $symbol, 'source' => $source, 'rule_id' => $ruleId,
            'minimum_free_shipping' => $this->decimal($minimum), 'remaining_for_free_shipping' => $this->decimal($remaining), 'message' => $message,
            'city' => $city ? ['id' => (int) $city->id, 'name' => (string) $city->name, 'stateId' => (int) $city->state_id, 'state' => (string) $city->state_name, 'urbanId' => $city->id_urbano ? (int) $city->id_urbano : null] : null];
    }

    private function currency(string $country): string { return ['SV'=>'USD','GT'=>'GTQ','CR'=>'CRC','PA'=>'USD','HN'=>'HNL'][strtoupper($country)] ?? 'USD'; }
    private function money(int $cents, string $symbol): string { return trim($symbol).$this->decimal($cents); }
    public function cents(string $value): int
    {
        $value = trim(str_replace(',', '', $value));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw ValidationException::withMessages(['shipping' => 'La configuracion de envio contiene un importe invalido.']);
        }
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = ((int) $matches[2] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) $cents++;
        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }
    public function decimal(int $cents): string { return intdiv($cents, 100).'.'.str_pad((string) abs($cents % 100), 2, '0', STR_PAD_LEFT); }
}
