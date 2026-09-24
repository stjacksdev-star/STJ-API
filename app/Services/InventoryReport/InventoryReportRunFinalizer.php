<?php

namespace App\Services\InventoryReport;

use Illuminate\Support\Facades\DB;

class InventoryReportRunFinalizer
{
    /** @return array<string, mixed> */
    public function finalize(string $reportDate): array
    {
        $runs = DB::table('stj_inventory_report_runs')
            ->whereDate('irr_report_date', $reportDate)
            ->whereIn('irr_status', ['CREATED', 'PROCESSING'])
            ->orderBy('irr_id')
            ->get();
        $summaries = [];

        foreach ($runs as $run) {
            $summaries[] = DB::transaction(function () use ($run): array {
                $timestamp = now();
                DB::table('stj_inventory_report_products')
                    ->where('irp_run_id', $run->irr_id)
                    ->whereIn('irp_status', ['PENDING', 'PROCESSING', 'RETRY_PENDING'])
                    ->update([
                        'irp_status' => DB::raw("CASE WHEN irp_last_error = 'El endpoint no devolvio el producto solicitado.' THEN 'NOT_RETURNED' ELSE 'FAILED' END"),
                        'irp_last_error' => DB::raw("COALESCE(irp_last_error, 'La ventana nocturna finalizo antes de procesar el producto.')"),
                        'irp_completed_at' => $timestamp,
                        'irp_updated_at' => $timestamp,
                    ]);

                DB::table('stj_inventory_report_requests')
                    ->where('irq_run_id', $run->irr_id)
                    ->where('irq_status', 'PENDING')
                    ->update([
                        'irq_status' => 'FAILED',
                        'irq_error' => 'La ventana nocturna finalizo con la solicitud pendiente.',
                        'irq_completed_at' => $timestamp,
                    ]);

                $counts = DB::table('stj_inventory_report_products')
                    ->where('irp_run_id', $run->irr_id)
                    ->selectRaw("SUM(CASE WHEN irp_status = 'FOUND' THEN 1 ELSE 0 END) found")
                    ->selectRaw("SUM(CASE WHEN irp_status = 'NOT_RETURNED' THEN 1 ELSE 0 END) not_returned")
                    ->selectRaw("SUM(CASE WHEN irp_status = 'NOT_FOUND' THEN 1 ELSE 0 END) not_found")
                    ->selectRaw("SUM(CASE WHEN irp_status = 'FAILED' THEN 1 ELSE 0 END) failed")
                    ->first();
                $rows = DB::table('stj_inventory_report_rows')->where('irw_run_id', $run->irr_id)->count();
                $notReturned = (int) ($counts->not_returned ?? 0);
                $failed = (int) ($counts->failed ?? 0);
                $status = $notReturned > 0 || $failed > 0 ? 'PARTIAL' : 'COMPLETE';

                DB::table('stj_inventory_report_runs')->where('irr_id', $run->irr_id)->update([
                    'irr_status' => $status,
                    'irr_found_products' => (int) ($counts->found ?? 0),
                    'irr_not_returned_products' => $notReturned,
                    'irr_not_found_products' => (int) ($counts->not_found ?? 0),
                    'irr_failed_products' => $failed,
                    'irr_result_rows' => $rows,
                    'irr_queries_closed_at' => $timestamp,
                    'irr_completed_at' => $timestamp,
                    'irr_updated_at' => $timestamp,
                ]);

                return [
                    'runId' => (int) $run->irr_id,
                    'countryCode' => (string) $run->irr_country_code,
                    'status' => $status,
                    'found' => (int) ($counts->found ?? 0),
                    'notReturned' => $notReturned,
                    'failed' => $failed,
                    'rows' => $rows,
                ];
            }, 3);
        }

        return [
            'date' => $reportDate,
            'finalized' => count($summaries),
            'runs' => $summaries,
        ];
    }
}
