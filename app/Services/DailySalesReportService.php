<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DailySalesReportService
{
    private const COUNTRIES = [
        1 => ['key' => 'sv', 'name' => 'El Salvador'],
        2 => ['key' => 'gt', 'name' => 'Guatemala'],
        3 => ['key' => 'cr', 'name' => 'Costa Rica'],
        5 => ['key' => 'pa', 'name' => 'Panamá'],
        7 => ['key' => 'hn', 'name' => 'Honduras'],
    ];

    public function __construct(private readonly Smtp2GoMailer $mailer) {}

    /** @return array<string, mixed> */
    public function send(?CarbonImmutable $date = null): array
    {
        $report = $this->generate($date);
        $to = (array) config('daily_sales_report.to', []);

        if ($to === []) {
            throw new RuntimeException('DAILY_SALES_REPORT_TO no contiene destinatarios válidos.');
        }

        $this->mailer->sendHtml(
            $to,
            "Venta del Día | St. Jack's Online | {$report['date']}",
            $this->html($report),
            (array) config('daily_sales_report.cc', []),
            (array) config('daily_sales_report.bcc', []),
        );

        return [...$report, 'sent' => true];
    }

    /** @return array<string, mixed> */
    public function generate(?CarbonImmutable $date = null): array
    {
        $timezone = (string) config('daily_sales_report.timezone', 'America/El_Salvador');
        $reportDate = ($date ?? CarbonImmutable::now($timezone)->subDay())->setTimezone($timezone)->startOfDay();
        $monthStart = $reportDate->startOfMonth();
        $dayEnd = $reportDate->endOfDay();
        $rate = $this->hnlRate($reportDate);

        $dailySales = $this->sales($reportDate, $dayEnd);
        $monthlySales = $this->sales($monthStart, $dayEnd);
        $dailyRefunds = $this->refunds($reportDate, $dayEnd);
        $monthlyRefunds = $this->refunds($monthStart, $dayEnd);

        return [
            'date' => $reportDate->toDateString(),
            'month_start' => $monthStart->toDateString(),
            'currency' => 'USD',
            'rates' => [
                'gtq_usd' => (float) config('daily_sales_report.gtq_usd_rate', 0.13049),
                'crc_usd' => (float) config('daily_sales_report.crc_usd_rate', 0.0017594),
                'hnl_usd' => $rate,
            ],
            'countries' => array_values(array_map(fn (array $country): array => $country, self::COUNTRIES)),
            'sales' => [
                'daily' => $this->countryTotals($dailySales, $rate),
                'month_to_date' => $this->countryTotals($monthlySales, $rate),
            ],
            'refunds' => [
                'daily' => $this->countryTotals($dailyRefunds, $rate),
                'month_to_date' => $this->countryTotals($monthlyRefunds, $rate),
            ],
            'payment_types' => [
                'TARJETA' => $this->countryTotals($dailySales, $rate, 'TARJETA'),
                'EFECTIVO' => $this->countryTotals($dailySales, $rate, 'EFECTIVO'),
            ],
        ];
    }

    private function sales(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payment', function ($join) {
                $join->on('payment.ppa_pedido', '=', 'orders.ped_id')
                    ->where('payment.ppa_estado', 'APROBADA');
            })
            ->whereIn('orders.ped_id_pais', array_keys(self::COUNTRIES))
            ->whereIn('orders.ped_origen', ['WEB', 'APP'])
            ->whereBetween('payment.ppa_fecha', [$start, $end])
            ->groupBy('orders.ped_id_pais', 'orders.ped_origen', 'payment.ppa_tipo')
            ->get([
                'orders.ped_id_pais as country_id',
                'orders.ped_origen as origin',
                'payment.ppa_tipo as payment_type',
                DB::raw('SUM(payment.ppa_monto_senv) as amount'),
            ]);
    }

    private function refunds(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payment', function ($join) {
                $join->on('payment.ppa_pedido', '=', 'orders.ped_id')
                    ->where('payment.ppa_estado', 'APROBADA')
                    ->where('payment.ppa_tipo', 'TARJETA');
            })
            ->whereIn('orders.ped_id_pais', array_keys(self::COUNTRIES))
            ->whereIn('orders.ped_origen', ['WEB', 'APP'])
            ->where('orders.ped_devolucion_realizada', '<>', 'N/A')
            ->whereBetween('payment.ppa_fecha', [$start, $end])
            ->groupBy('orders.ped_id_pais', 'orders.ped_origen')
            ->get([
                'orders.ped_id_pais as country_id',
                'orders.ped_origen as origin',
                DB::raw("'TARJETA' as payment_type"),
                DB::raw('SUM(orders.ped_monto_devolucion) as amount'),
            ]);
    }

    /** @return array<string, mixed> */
    private function countryTotals(Collection $rows, float $hnlRate, ?string $paymentType = null): array
    {
        $result = [];
        $grandTotal = 0.0;

        foreach (self::COUNTRIES as $id => $country) {
            $web = $this->convertedAmount($rows, $id, 'WEB', $hnlRate, $paymentType);
            $app = $this->convertedAmount($rows, $id, 'APP', $hnlRate, $paymentType);
            $result[$country['key']] = ['web' => $web, 'app' => $app, 'total' => round($web + $app, 2)];
            $grandTotal += $web + $app;
        }

        return ['countries' => $result, 'total' => round($grandTotal, 2)];
    }

    private function convertedAmount(Collection $rows, int $countryId, string $origin, float $hnlRate, ?string $paymentType): float
    {
        if ($countryId === 5 && $origin === 'APP') {
            return 0.0;
        }

        $amount = $rows
            ->filter(fn (object $row): bool => (int) $row->country_id === $countryId
                && strtoupper((string) $row->origin) === $origin
                && ($paymentType === null || strtoupper((string) $row->payment_type) === $paymentType))
            ->sum(fn (object $row): float => (float) $row->amount);

        return round($amount * $this->usdRate($countryId, $hnlRate), 2);
    }

    private function usdRate(int $countryId, float $hnlRate): float
    {
        return match ($countryId) {
            2 => (float) config('daily_sales_report.gtq_usd_rate', 0.13049),
            3 => (float) config('daily_sales_report.crc_usd_rate', 0.0017594),
            7 => $hnlRate,
            default => 1.0,
        };
    }

    private function hnlRate(CarbonImmutable $date): float
    {
        $rate = DB::table('tasa_hnl_usd')
            ->whereDate('fecha', '<=', $date->toDateString())
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->value('tasa');

        return $rate !== null ? (float) $rate : (float) config('daily_sales_report.hnl_usd_fallback_rate', 0);
    }

    /** @param array<string, mixed> $report */
    private function html(array $report): string
    {
        $style = '<style>body{font-family:Arial,sans-serif;color:#222}table{width:100%;border-collapse:collapse;margin:12px 0 30px}th,td{border:1px solid #d3d3d3;padding:8px;text-align:right}th{background:#007ac9;color:#fff}th:first-child,td:first-child{text-align:left}.meta{color:#555;font-size:12px}</style>';
        $content = '<h2>Reporte diario regional</h2><p>Fecha: <strong>'.$this->e($report['date']).'</strong> · Moneda: USD</p>';
        $content .= '<h3>Devoluciones</h3>'.$this->comparisonTable($report['refunds']['daily'], $report['refunds']['month_to_date'], $report['date'], 'DEVOLUCIÓN ACUMULADA DEL MES');
        $content .= '<h3>Ventas</h3>'.$this->comparisonTable($report['sales']['daily'], $report['sales']['month_to_date'], $report['date'], 'VENTA ACUMULADA DEL MES');
        $content .= '<h3>Venta por tipo de pago</h3>'.$this->paymentTable($report['payment_types']);
        $content .= '<p class="meta">Tasas usadas: GTQ/USD '.$this->money($report['rates']['gtq_usd'], 5).' · CRC/USD '.$this->money($report['rates']['crc_usd'], 7).' · HNL/USD '.$this->money($report['rates']['hnl_usd'], 7).'</p>';
        $content .= '<p class="meta">Proceso automático generado: '.$this->e(now((string) config('daily_sales_report.timezone'))->format('d/m/Y H:i:s')).'</p>';

        return $style.$content;
    }

    /** @param array<string, mixed> $daily @param array<string, mixed> $monthly */
    private function comparisonTable(array $daily, array $monthly, string $date, string $monthlyLabel): string
    {
        return $this->tableHeader().'<tbody>'.$this->totalRow($date, $daily).$this->totalRow($monthlyLabel, $monthly).'</tbody></table>';
    }

    /** @param array<string, mixed> $paymentTypes */
    private function paymentTable(array $paymentTypes): string
    {
        return $this->tableHeader().'<tbody>'.$this->totalRow('TARJETA', $paymentTypes['TARJETA']).$this->totalRow('EFECTIVO', $paymentTypes['EFECTIVO']).'</tbody></table>';
    }

    private function tableHeader(): string
    {
        $columns = '';
        foreach (self::COUNTRIES as $country) {
            $columns .= '<th>'.$this->e($country['name']).' Web</th><th>'.$this->e($country['name']).' App</th>';
        }

        return '<table><thead><tr><th>Periodo</th>'.$columns.'<th>Total</th></tr></thead>';
    }

    /** @param array<string, mixed> $totals */
    private function totalRow(string $label, array $totals): string
    {
        $cells = '';
        foreach (self::COUNTRIES as $country) {
            $values = $totals['countries'][$country['key']];
            $cells .= '<td>'.$this->money($values['web']).'</td><td>'.$this->money($values['app']).'</td>';
        }

        return '<tr><td>'.$this->e($label).'</td>'.$cells.'<td><strong>'.$this->money($totals['total']).'</strong></td></tr>';
    }

    private function money(mixed $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, '.', ',');
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
