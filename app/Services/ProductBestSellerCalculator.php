<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductBestSellerCalculator
{
    public const COUNTRIES = [1, 2, 3, 5, 7];

    public const PERIODS = [7, 14, 30];

    /**
     * @return Collection<int, object>
     */
    public function salesFor(int $countryId, int $days): Collection
    {
        $this->assertSupported($countryId, $days);

        return DB::table('stj_pedidos as ped')
            ->join('stj_pedidos_pago as pag', function ($join) {
                $join->on('pag.ppa_pedido', '=', 'ped.ped_id')
                    ->where('pag.ppa_estado', 'APROBADA');
            })
            ->join('stj_pedidos_detalle as det', function ($join) {
                $join->on('det.car_ref', '=', 'pag.ppa_ref')
                    ->on('det.car_pais', '=', 'ped.ped_id_pais');
            })
            ->join('stj_productos as pro', 'pro.pro_id', '=', 'det.car_producto')
            ->join('stj_producto_pais as ppa', function ($join) {
                $join->on('ppa.ppa_producto', '=', 'pro.pro_id')
                    ->on('ppa.ppa_pais', '=', 'ped.ped_id_pais');
            })
            ->where('ped.ped_id_pais', $countryId)
            ->where('det.car_accion', 'AGREGADO')
            ->where('ppa.ppa_estado', 'ACTIVO')
            ->whereNotIn('det.car_producto', [5645, 5646])
            ->whereRaw($this->dateFilter(), [$days])
            ->groupBy('det.car_producto', 'ped.ped_id_pais')
            ->orderByDesc('unidades_vendidas')
            ->orderByDesc('monto_vendido')
            ->selectRaw('
                det.car_producto AS producto_id,
                ped.ped_id_pais AS pais,
                SUM(det.car_cantidad) AS unidades_vendidas,
                COUNT(DISTINCT ped.ped_id) AS pedidos_diferentes,
                ROUND(
                    SUM(
                        ROUND(
                            det.car_precio
                            * det.car_cantidad
                            * (1 - COALESCE(det.car_descuento, 0) / 100),
                            2
                        )
                    ),
                    2
                ) AS monto_vendido
            ')
            ->get();
    }

    public function calculateAndStore(int $countryId, int $days): int
    {
        $rows = $this->salesFor($countryId, $days);
        $period = $this->period($days);
        $executedAt = CarbonImmutable::now()->startOfSecond();

        DB::transaction(function () use ($rows, $countryId, $period, $executedAt) {
            $payload = $rows
                ->values()
                ->map(fn (object $row, int $index) => [
                    'pme_producto' => (int) $row->producto_id,
                    'pme_pais' => $countryId,
                    'pme_periodo' => $period,
                    'pme_ventas_unidades' => (int) $row->unidades_vendidas,
                    'pme_ventas_pedidos' => (int) $row->pedidos_diferentes,
                    'pme_monto_vendido' => round((float) $row->monto_vendido, 2),
                    'pme_ranking_ventas' => $index + 1,
                    'pme_fecha_calculo' => $executedAt->toDateTimeString(),
                ]);

            $payload->chunk(500)->each(function (Collection $chunk) {
                DB::table('stj_producto_metricas')->upsert(
                    $chunk->all(),
                    ['pme_producto', 'pme_pais', 'pme_periodo'],
                    [
                        'pme_ventas_unidades',
                        'pme_ventas_pedidos',
                        'pme_monto_vendido',
                        'pme_ranking_ventas',
                        'pme_fecha_calculo',
                    ],
                );
            });

            DB::table('stj_producto_metricas')
                ->where('pme_pais', $countryId)
                ->where('pme_periodo', $period)
                ->where('pme_fecha_calculo', '!=', $executedAt->toDateTimeString())
                ->delete();
        });

        return $rows->count();
    }

    public function period(int $days): string
    {
        if (! in_array($days, self::PERIODS, true)) {
            throw new \InvalidArgumentException("El período {$days} no está soportado.");
        }

        return "{$days}D";
    }

    private function assertSupported(int $countryId, int $days): void
    {
        if (! in_array($countryId, self::COUNTRIES, true)) {
            throw new \InvalidArgumentException("El país {$countryId} no está soportado.");
        }

        $this->period($days);
    }

    private function dateFilter(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return "pag.ppa_fecha >= date('now', '-' || ? || ' days')";
        }

        return 'pag.ppa_fecha >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    }
}
