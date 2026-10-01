<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

class ManagementCyberMondayReportService
{
    private const COUNTRIES = [1 => 'El Salvador', 2 => 'Guatemala', 3 => 'Costa Rica'];

    private const PERIODS = [
        'sunday' => ['label' => 'DOMINGO', 'previousDate' => '2025-11-30', 'currentDate' => '2026-11-29'],
        'monday' => ['label' => 'LUNES', 'previousDate' => '2025-12-01', 'currentDate' => '2026-11-30'],
    ];

    /** @return array<string, mixed> */
    public function report(int $country): array
    {
        $sales = $this->sales($country);
        $periods = [];

        foreach (self::PERIODS as $key => $period) {
            $rows = [];
            foreach (range(0, 23) as $hour) {
                $previous = $sales[$period['previousDate']][$hour] ?? ['orders' => 0, 'amount' => 0.0];
                $current = $sales[$period['currentDate']][$hour] ?? ['orders' => 0, 'amount' => 0.0];
                $rows[] = $this->comparison($hour, $previous, $current);
            }

            $previousTotals = ['orders' => array_sum(array_column($rows, 'previousOrders')), 'amount' => round(array_sum(array_column($rows, 'previousAmount')), 2)];
            $currentTotals = ['orders' => array_sum(array_column($rows, 'currentOrders')), 'amount' => round(array_sum(array_column($rows, 'currentAmount')), 2)];

            $periods[$key] = [
                ...$period,
                'rows' => $rows,
                'totals' => $this->comparison(null, $previousTotals, $currentTotals),
            ];
        }

        return [
            'country' => ['id' => $country, 'name' => self::COUNTRIES[$country]],
            'countries' => collect(self::COUNTRIES)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all(),
            'previousYear' => 2025,
            'currentYear' => 2026,
            'periods' => $periods,
        ];
    }

    /** @return array{contents: string, filename: string} */
    public function export(int $country): array
    {
        $report = $this->report($country);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ReporteHoras');
        $sheet->setCellValue('A1', 'Comparativo por Hora - '.$report['country']['name'])->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $row = 3;

        foreach ($report['periods'] as $period) {
            $sheet->setCellValue("A{$row}", $period['label'].' - 2025 vs 2026')->mergeCells("A{$row}:I{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
            $sheet->fromArray($this->headers(), null, 'A'.$row);
            $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A{$row}:I{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('343A40');
            $row++;
            foreach ($period['rows'] as $data) {
                $sheet->fromArray($this->excelRow($data), null, 'A'.$row++);
            }
            $sheet->fromArray(['Total', ...array_slice($this->excelRow($period['totals']), 1)], null, 'A'.$row);
            $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:I{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
            $row += 2;
        }

        $sheet->getStyle('C1:C'.$row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('E1:E'.$row)->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('G1:G'.$row)->getNumberFormat()->setFormatCode('$#,##0.00;[Red]-$#,##0.00');
        $sheet->getStyle('H1:I'.$row)->getNumberFormat()->setFormatCode('0.00%');
        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xls($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return ['contents' => $contents, 'filename' => 'reporte_horas_cyber.xls'];
    }

    /** @return array<string, array<int, array{orders: int, amount: float}>> */
    private function sales(int $country): array
    {
        $dates = collect(self::PERIODS)->flatMap(fn (array $period) => [$period['previousDate'], $period['currentDate']])->all();
        $query = DB::table('stj_pedidos as orders')
            ->join('stj_pedidos_pago as payments', 'payments.ppa_pedido', '=', 'orders.ped_id')
            ->where('payments.ppa_estado', 'APROBADA')
            ->where('orders.ped_id_pais', $country)
            ->where(function ($query) use ($dates) {
                foreach ($dates as $date) {
                    $query->orWhereBetween('payments.ppa_fecha', [$date.' 00:00:00', $date.' 23:59:59']);
                }
            })
            ->get(['payments.ppa_fecha as date', 'payments.ppa_monto_senv as amount']);

        $result = [];
        foreach ($query as $sale) {
            $timestamp = strtotime((string) $sale->date);
            $date = date('Y-m-d', $timestamp);
            $hour = (int) date('G', $timestamp);
            $result[$date][$hour] ??= ['orders' => 0, 'amount' => 0.0];
            $result[$date][$hour]['orders']++;
            $result[$date][$hour]['amount'] = round($result[$date][$hour]['amount'] + (float) $sale->amount, 2);
        }

        return $result;
    }

    /** @param array{orders: int, amount: float} $previous @param array{orders: int, amount: float} $current */
    private function comparison(?int $hour, array $previous, array $current): array
    {
        $ordersDifference = $current['orders'] - $previous['orders'];
        $amountDifference = round($current['amount'] - $previous['amount'], 2);

        return [
            'hour' => $hour === null ? null : sprintf('%02d:00', $hour),
            'previousOrders' => $previous['orders'], 'previousAmount' => $previous['amount'],
            'currentOrders' => $current['orders'], 'currentAmount' => $current['amount'],
            'ordersDifference' => $ordersDifference, 'amountDifference' => $amountDifference,
            'ordersPercentage' => $previous['orders'] > 0 ? round($ordersDifference / $previous['orders'] * 100, 2) : ($current['orders'] > 0 ? 100.0 : 0.0),
            'amountPercentage' => $previous['amount'] > 0 ? round($amountDifference / $previous['amount'] * 100, 2) : ($current['amount'] > 0 ? 100.0 : 0.0),
        ];
    }

    private function headers(): array
    {
        return ['Hora', '2025 Pedidos', '2025 Monto', '2026 Pedidos', '2026 Monto', 'Diferencia Pedidos', 'Diferencia Monto', '% Pedidos', '% Monto'];
    }

    private function excelRow(array $row): array
    {
        return [$row['hour'], $row['previousOrders'], $row['previousAmount'], $row['currentOrders'], $row['currentAmount'], $row['ordersDifference'], $row['amountDifference'], $row['ordersPercentage'] / 100, $row['amountPercentage'] / 100];
    }
}
