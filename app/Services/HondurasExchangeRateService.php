<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HondurasExchangeRateService
{
    /** @return array{date: string, rate: float, created: bool} */
    public function sync(?CarbonInterface $date = null): array
    {
        $response = Http::acceptJson()
            ->connectTimeout((int) config('hn_exchange_rate.connect_timeout', 5))
            ->timeout((int) config('hn_exchange_rate.timeout', 15))
            ->retry(2, 500)
            ->get((string) config('hn_exchange_rate.url'));
        $response->throw();

        $rate = $response->json('rates.USD');
        if (! is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('La respuesta del proveedor no contiene una tasa USD valida para HNL.');
        }

        $date ??= now((string) config('hn_exchange_rate.timezone', 'America/Tegucigalpa'));
        $dateValue = $date->toDateString();
        $created = ! DB::table('tasa_hnl_usd')->where('fecha', $dateValue)->exists();
        DB::table('tasa_hnl_usd')->updateOrInsert(
            ['fecha' => $dateValue],
            ['tasa' => (float) $rate],
        );

        return ['date' => $dateValue, 'rate' => (float) $rate, 'created' => $created];
    }
}
