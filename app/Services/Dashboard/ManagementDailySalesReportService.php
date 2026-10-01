<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    /** @return array<string, mixed> */
    public function monthlyReport(int $month = 0, int $country = 0): array
    {
        $rows = [];
        foreach ($this->legacySales($month, $country) as $sale) {
            $this->accumulateMonthly($rows, (string) $sale->date, (int) $sale->country_id, (float) $sale->amount);
        }
        foreach ($this->currentSales($month, $country) as $sale) {
            $this->accumulateMonthly($rows, (string) $sale->date, (int) $sale->country_id, (float) $sale->amount);
        }

        ksort($rows);
        $totals = array_fill_keys(self::YEARS, 0.0);
        $growth = 0.0;
        $rows = array_values(array_map(function (array $row) use (&$totals, &$growth): array {
            foreach (self::YEARS as $year) {
                $totals[$year] = round($totals[$year] + $row['years'][$year], 2);
            }
            $row['growth'] = round($row['years'][2026] - $row['years'][2025], 2);
            $growth = round($growth + $row['growth'], 2);

            return $row;
        }, $rows));

        return [
            'filters' => ['month' => $month, 'country' => $country],
            'years' => self::YEARS,
            'countries' => collect(self::COUNTRIES)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all(),
            'rows' => $rows,
            'totals' => ['years' => $totals, 'growth' => $growth],
        ];
    }

    /** @return array{contents: string, filename: string} */
    public function exportMonthly(int $month = 0, int $country = 0): array
    {
        $report = $this->monthlyReport($month, $country);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ventas por Mes');
        $spreadsheet->getProperties()->setCreator('STJ')->setTitle('Reporte Ventas EC por Mes')->setSubject('Reporte Ventas EC por Mes');

        $countryLabel = $country === 0 ? 'Todos los paises' : (self::COUNTRIES[$country] ?? "Pais ID: {$country}");
        $monthLabel = $month === 0 ? 'Todos' : (string) $month;
        $sheet->setCellValue('A1', 'Reporte Ventas EC por Mes')->mergeCells('A1:F1');
        $sheet->setCellValue('A2', "Pais: {$countryLabel} | Mes: {$monthLabel}")->mergeCells('A2:F2');
        $sheet->fromArray(['Mes', '2023', '2024', '2025', '2026', 'Crecimiento 2026 vs 2025'], null, 'A3');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1:F3')->getAlignment()->setHorizontal('center');
        $sheet->getStyle('A2:F3')->getFont()->setBold(true);
        $sheet->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');

        $excelRow = 4;
        foreach ($report['rows'] as $row) {
            $sheet->fromArray([$row['monthName'], $row['years'][2023], $row['years'][2024], $row['years'][2025], $row['years'][2026], $row['growth']], null, 'A'.$excelRow++);
        }
        $sheet->fromArray(['Totales', $report['totals']['years'][2023], $report['totals']['years'][2024], $report['totals']['years'][2025], $report['totals']['years'][2026], $report['totals']['growth']], null, 'A'.$excelRow);
        $sheet->getStyle('A'.$excelRow.':F'.$excelRow)->getFont()->setBold(true);
        $sheet->getStyle('A'.$excelRow.':F'.$excelRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $sheet->getStyle('B4:F'.$excelRow)->getNumberFormat()->setFormatCode('"$"#,##0.00;[Red]"$"#,##0.00');
        $sheet->getStyle('A1:F'.$excelRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A4');

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();
        $monthFile = $month === 0 ? 'Todos' : str_pad((string) $month, 2, '0', STR_PAD_LEFT);
        $countryFile = $country === 0 ? 'Todos' : (string) $country;

        return ['contents' => $contents, 'filename' => "reporte_ventas_ec_mensual_mes{$monthFile}_pais{$countryFile}.xlsx"];
    }

    private function legacySales(int $month, int $country): iterable
    {
        return DB::table('rep_bihoral_ec')
            ->select(['Total as amount', 'Pais as country_id', 'Fecha as date'])
            ->whereBetween('Fecha', ['2023-01-01 00:00:00', '2024-12-31 23:59:59'])
            ->when($month !== 0, fn ($query) => $query->whereMonth('Fecha', $month))
            ->when($country !== 0, fn ($query) => $query->where('Pais', $country))
            ->get();
    }

    private function currentSales(int $month, int $country): iterable
    {
        return DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', 'payments.ppa_pedido', '=', 'orders.ped_id')
            ->join('stj_tiendas as stores', function ($join) {
                $join->on('stores.tie_codigo', '=', 'orders.ped_tienda')
                    ->on('stores.tie_pais', '=', 'orders.ped_id_pais');
            })
            ->select(['payments.ppa_monto_senv as amount', 'orders.ped_id_pais as country_id', 'payments.ppa_fecha as date'])
            ->where('payments.ppa_estado', 'APROBADA')
            ->whereBetween('payments.ppa_fecha', ['2025-01-01 00:00:00', '2026-12-31 23:59:59'])
            ->when($month !== 0, fn ($query) => $query->whereMonth('payments.ppa_fecha', $month))
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

    /** @param array<int, array<string, mixed>> $rows */
    private function accumulateMonthly(array &$rows, string $date, int $country, float $amount): void
    {
        if (! isset(self::COUNTRIES[$country])) {
            return;
        }
        $timestamp = strtotime($date);
        $year = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        if (! in_array($year, self::YEARS, true)) {
            return;
        }
        $names = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
        $rows[$month] ??= ['month' => $month, 'monthName' => $names[$month], 'years' => array_fill_keys(self::YEARS, 0.0)];
        $rows[$month]['years'][$year] = round($rows[$month]['years'][$year] + round($amount * $this->usdRate($country), 2), 2);
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
