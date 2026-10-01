<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class ManagementDailySalesReportService
{
    private const YEARS = [2023, 2024, 2025, 2026];

    private const COUNTRIES = [
        1 => 'El Salvador',
        2 => 'Guatemala',
        3 => 'Costa Rica',
        7 => 'Honduras',
    ];

    /** @return array<string, mixed> */
    public function report(int $month, int $country = 0): array
    {
        $rows = [];

        foreach ($this->legacySales($month, $country) as $sale) {
            $this->accumulate($rows, (string) $sale->date, (int) $sale->country_id, (float) $sale->amount);
        }

        foreach ($this->currentSales($month, $country) as $sale) {
            $this->accumulate($rows, (string) $sale->date, (int) $sale->country_id, (float) $sale->amount);
        }

        ksort($rows);
        $rows = array_values(array_map(function (array $row): array {
            $row['grandTotal'] = round(array_sum($row['years']), 2);
            $row['differences'] = [
                '2026-2025' => round($row['years'][2026] - $row['years'][2025], 2),
                '2026-2024' => round($row['years'][2026] - $row['years'][2024], 2),
                '2026-2023' => round($row['years'][2026] - $row['years'][2023], 2),
            ];

            return $row;
        }, $rows));

        return [
            'filters' => ['month' => $month, 'country' => $country],
            'years' => self::YEARS,
            'countries' => collect(self::COUNTRIES)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all(),
            'rows' => $rows,
            'chart' => [
                'labels' => array_column($rows, 'day'),
                'series' => collect(self::YEARS)->map(fn (int $year) => [
                    'year' => $year,
                    'data' => array_map(fn (array $row) => $row['years'][$year], $rows),
                ])->all(),
            ],
        ];
    }

    /** @return array{contents: string, filename: string} */
    public function export(int $month, int $country = 0): array
    {
        $report = $this->report($month, $country);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte de Ventas');
        $sheet->fromArray(['Dia', '2023', '2024', '2025', '2026', 'Grand Total', '2026 - 2025', '2026 - 2024', '2026 - 2023'], null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        $excelRow = 2;
        foreach ($report['rows'] as $row) {
            $sheet->fromArray([
                $row['day'],
                $row['years'][2023],
                $row['years'][2024],
                $row['years'][2025],
                $row['years'][2026],
                $row['grandTotal'],
                $row['differences']['2026-2025'],
                $row['differences']['2026-2024'],
                $row['differences']['2026-2023'],
            ], null, 'A'.$excelRow);

            foreach (['G', 'H', 'I'] as $column) {
                $cell = $sheet->getCell($column.$excelRow);
                if ((float) $cell->getValue() > 0) {
                    $sheet->getStyle($column.$excelRow)->getFont()->setBold(true);
                } else {
                    $sheet->getStyle($column.$excelRow)->getFont()->getColor()->setARGB(Color::COLOR_RED);
                }
            }
            $excelRow++;
        }

        $lastRow = max(2, $excelRow - 1);
        $sheet->getStyle('B2:I'.$lastRow)->getNumberFormat()->setFormatCode('$#,##0.00;[Red]-$#,##0.00');
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $writer = new Xls($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return ['contents' => $contents, 'filename' => 'reporte_ventas.xls'];
    }

    private function legacySales(int $month, int $country): iterable
    {
        return DB::table('rep_bihoral_ec')
            ->select(['Total as amount', 'Pais as country_id', 'Fecha as date'])
            ->whereBetween('Fecha', ['2023-01-01 00:00:00', '2024-12-31 23:59:59'])
            ->whereMonth('Fecha', $month)
            ->when($country !== 0, fn ($query) => $query->where('Pais', $country))
            ->get();
    }

    private function currentSales(int $month, int $country): iterable
    {
        return DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', 'payments.ppa_pedido', '=', 'orders.ped_id')
            ->select(['payments.ppa_monto_senv as amount', 'orders.ped_id_pais as country_id', 'payments.ppa_fecha as date'])
            ->where('payments.ppa_estado', 'APROBADA')
            ->whereBetween('payments.ppa_fecha', ['2025-01-01 00:00:00', '2026-12-31 23:59:59'])
            ->whereMonth('payments.ppa_fecha', $month)
            ->when($country !== 0, fn ($query) => $query->where('orders.ped_id_pais', $country))
            ->get();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function accumulate(array &$rows, string $date, int $country, float $amount): void
    {
        $timestamp = strtotime($date);
        $year = (int) date('Y', $timestamp);
        $day = (int) date('j', $timestamp);
        if (! in_array($year, self::YEARS, true)) {
            return;
        }

        $rows[$day] ??= ['day' => $day, 'years' => array_fill_keys(self::YEARS, 0.0)];
        $rows[$day]['years'][$year] = round($rows[$day]['years'][$year] + round($amount * $this->usdRate($country), 2), 2);
    }

    private function usdRate(int $country): float
    {
        return match ($country) {
            2 => 0.13049,
            3 => 0.0017594,
            7 => (float) (DB::table('tasa_hnl_usd')->orderByDesc('fecha')->orderByDesc('id')->value('tasa') ?? 0),
            default => 1.0,
        };
    }
}
