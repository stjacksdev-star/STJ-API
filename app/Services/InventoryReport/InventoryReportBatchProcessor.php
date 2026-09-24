<?php

namespace App\Services\InventoryReport;

use App\Services\InventoryReport\Exceptions\InventoryReportRequestException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class InventoryReportBatchProcessor
{
    public function __construct(private readonly InventoryReportClient $client) {}

    /**
     * Procesa como maximo un lote de una corrida.
     *
     * @return array<string, mixed>
     */
    public function process(?string $countryCode = null, ?Carbon $reportDate = null, ?int $batchSize = null): array
    {
        $run = $this->eligibleRun($countryCode, $reportDate)->first();
        if ($run === null) {
            return ['ok' => true, 'processed' => false, 'message' => 'No hay corridas con productos pendientes.'];
        }

        $limit = max(1, min(500, $batchSize ?? (int) config('inventory_report.batch_size', 100)));
        $maxAttempts = max(1, (int) config('inventory_report.max_attempts', 3));
        $this->recoverStaleProducts((int) $run->irr_id, $maxAttempts);

        $claim = $this->claim((int) $run->irr_id, $limit, $maxAttempts);
        if ($claim['products'] === []) {
            $this->refreshRun((int) $run->irr_id, $maxAttempts);

            return [
                'ok' => true,
                'processed' => false,
                'runId' => (int) $run->irr_id,
                'countryCode' => (string) $run->irr_country_code,
                'message' => 'La corrida no tiene productos elegibles para procesar.',
            ];
        }

        $products = collect($claim['products']);
        $codes = $products->pluck('irp_code')->map(static fn ($code): string => trim((string) $code))->all();
        $requestId = (int) $claim['request_id'];
        $summary = [
            'ok' => true,
            'processed' => true,
            'runId' => (int) $run->irr_id,
            'requestId' => $requestId,
            'countryCode' => (string) $run->irr_country_code,
            'products' => count($codes),
            'rows' => 0,
            'found' => 0,
            'retryPending' => 0,
            'notReturned' => 0,
            'failed' => 0,
            'warnings' => [],
        ];

        try {
            $result = $this->client->fetch((string) $run->irr_country_code, $codes);
            $summary['rows'] = count($result->response->rows);
            $summary['warnings'] = $result->response->warnings;

            DB::transaction(function () use ($products, $result, $requestId, $maxAttempts, &$summary): void {
                $byCode = $products->keyBy(static fn (object $product): string => trim((string) $product->irp_code));
                $timestamp = now();
                $rows = collect($result->response->rows)->map(function (array $row) use ($byCode, $requestId, $timestamp): array {
                    $product = $byCode->get($row['code']);

                    return [
                        'irw_run_id' => (int) $product->irp_run_id,
                        'irw_product_id' => (int) $product->irp_id,
                        'irw_request_id' => $requestId,
                        'irw_store' => $row['store'],
                        'irw_size' => $row['size'],
                        'irw_quantity' => $row['quantity'],
                        'irw_sale_price' => $row['sale_price'],
                        'irw_created_at' => $timestamp,
                        'irw_updated_at' => $timestamp,
                    ];
                })->all();

                if ($rows !== []) {
                    DB::table('stj_inventory_report_rows')->upsert(
                        $rows,
                        ['irw_run_id', 'irw_product_id', 'irw_store', 'irw_size'],
                        ['irw_request_id', 'irw_quantity', 'irw_sale_price', 'irw_updated_at'],
                    );
                }

                $returned = array_fill_keys($result->response->returnedCodes, true);
                foreach ($products as $product) {
                    $found = isset($returned[trim((string) $product->irp_code)]);
                    $terminal = (int) $product->irp_attempts >= $maxAttempts;
                    $status = $found ? 'FOUND' : ($terminal ? 'NOT_RETURNED' : 'RETRY_PENDING');
                    DB::table('stj_inventory_report_products')->where('irp_id', $product->irp_id)->update([
                        'irp_status' => $status,
                        'irp_last_http_status' => $result->httpStatus,
                        'irp_last_error' => $found ? null : 'El endpoint no devolvio el producto solicitado.',
                        'irp_completed_at' => in_array($status, ['FOUND', 'NOT_RETURNED'], true) ? $timestamp : null,
                        'irp_updated_at' => $timestamp,
                    ]);

                    $found ? $summary['found']++ : ($terminal ? $summary['notReturned']++ : $summary['retryPending']++);
                }

                DB::table('stj_inventory_report_requests')->where('irq_id', $requestId)->update([
                    'irq_returned_products' => count($result->response->returnedCodes),
                    'irq_returned_rows' => count($result->response->rows),
                    'irq_http_status' => $result->httpStatus,
                    'irq_duration_ms' => $result->durationMs,
                    'irq_status' => 'SUCCESS',
                    'irq_error' => $result->response->warnings === [] ? null : implode(' | ', $result->response->warnings),
                    'irq_completed_at' => $timestamp,
                ]);
                DB::table('stj_inventory_report_runs')->where('irr_id', $products->first()->irp_run_id)->update([
                    'irr_last_error' => null,
                    'irr_updated_at' => $timestamp,
                ]);
            });
        } catch (Throwable $exception) {
            $requestException = $exception instanceof InventoryReportRequestException ? $exception : null;
            DB::transaction(function () use ($products, $requestId, $maxAttempts, $exception, $requestException, &$summary): void {
                $timestamp = now();
                foreach ($products as $product) {
                    $terminal = (int) $product->irp_attempts >= $maxAttempts;
                    $status = $terminal ? 'FAILED' : 'RETRY_PENDING';
                    DB::table('stj_inventory_report_products')->where('irp_id', $product->irp_id)->update([
                        'irp_status' => $status,
                        'irp_last_http_status' => $requestException?->httpStatus,
                        'irp_last_error' => mb_substr($exception->getMessage(), 0, 65000),
                        'irp_completed_at' => $terminal ? $timestamp : null,
                        'irp_updated_at' => $timestamp,
                    ]);
                    $terminal ? $summary['failed']++ : $summary['retryPending']++;
                }

                DB::table('stj_inventory_report_requests')->where('irq_id', $requestId)->update([
                    'irq_http_status' => $requestException?->httpStatus,
                    'irq_duration_ms' => $requestException?->durationMs,
                    'irq_status' => 'FAILED',
                    'irq_error' => mb_substr($exception->getMessage(), 0, 65000),
                    'irq_completed_at' => $timestamp,
                ]);
                DB::table('stj_inventory_report_runs')->where('irr_id', $products->first()->irp_run_id)->update([
                    'irr_last_error' => mb_substr($exception->getMessage(), 0, 65000),
                    'irr_updated_at' => $timestamp,
                ]);
            });
            $summary['ok'] = false;
            $summary['error'] = $exception->getMessage();
        }

        $summary['run'] = $this->refreshRun((int) $run->irr_id, $maxAttempts);

        return $summary;
    }

    private function eligibleRun(?string $countryCode, ?Carbon $reportDate): Builder
    {
        $countryCode = $countryCode === null ? null : strtoupper(trim($countryCode));

        return DB::table('stj_inventory_report_runs')
            ->whereIn('irr_status', ['CREATED', 'PROCESSING'])
            ->when($countryCode, fn (Builder $query) => $query->where('irr_country_code', $countryCode))
            ->when($reportDate, fn (Builder $query) => $query->whereDate('irr_report_date', $reportDate->toDateString()))
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('stj_inventory_report_products')
                    ->whereColumn('irp_run_id', 'irr_id')
                    ->whereIn('irp_status', ['PENDING', 'RETRY_PENDING', 'PROCESSING']);
            })
            ->orderBy('irr_updated_at')
            ->orderBy('irr_id');
    }

    /** @return array{products: array<int, object>, request_id: int|null} */
    private function claim(int $runId, int $limit, int $maxAttempts): array
    {
        return DB::transaction(function () use ($runId, $limit, $maxAttempts): array {
            $next = DB::table('stj_inventory_report_products')
                ->where('irp_run_id', $runId)
                ->whereIn('irp_status', ['PENDING', 'RETRY_PENDING'])
                ->where('irp_attempts', '<', $maxAttempts)
                ->orderByDesc('irp_attempts')
                ->orderBy('irp_id')
                ->lockForUpdate()
                ->first(['irp_attempts']);
            if ($next === null) {
                return ['products' => [], 'request_id' => null];
            }

            $currentAttempts = (int) $next->irp_attempts;
            $effectiveLimit = match (true) {
                $currentAttempts >= 2 => min($limit, max(1, (int) config('inventory_report.final_retry_batch_size', 5))),
                $currentAttempts === 1 => min($limit, max(1, (int) config('inventory_report.retry_batch_size', 20))),
                default => $limit,
            };
            $products = DB::table('stj_inventory_report_products')
                ->where('irp_run_id', $runId)
                ->whereIn('irp_status', ['PENDING', 'RETRY_PENDING'])
                ->where('irp_attempts', $currentAttempts)
                ->orderBy('irp_id')
                ->limit($effectiveLimit)
                ->lockForUpdate()
                ->get();

            $timestamp = now();
            DB::table('stj_inventory_report_products')->whereIn('irp_id', $products->pluck('irp_id'))->update([
                'irp_status' => 'PROCESSING',
                'irp_attempts' => DB::raw('irp_attempts + 1'),
                'irp_first_requested_at' => DB::raw('COALESCE(irp_first_requested_at, CURRENT_TIMESTAMP)'),
                'irp_last_requested_at' => $timestamp,
                'irp_last_error' => null,
                'irp_updated_at' => $timestamp,
            ]);

            $products = DB::table('stj_inventory_report_products')->whereIn('irp_id', $products->pluck('irp_id'))->get();
            $run = DB::table('stj_inventory_report_runs')->where('irr_id', $runId)->lockForUpdate()->first();
            $country = config("inventory_report.countries.{$run->irr_country_code}");
            $requestId = DB::table('stj_inventory_report_requests')->insertGetId([
                'irq_run_id' => $runId,
                'irq_adapter' => (string) $country['adapter'],
                'irq_attempt' => (int) $products->max('irp_attempts'),
                'irq_batch_key' => (string) Str::uuid(),
                'irq_endpoint' => (string) $country['url'],
                'irq_requested_products' => $products->count(),
                'irq_status' => 'PENDING',
                'irq_started_at' => $timestamp,
                'irq_created_at' => $timestamp,
            ]);

            DB::table('stj_inventory_report_runs')->where('irr_id', $runId)->update([
                'irr_status' => 'PROCESSING',
                'irr_updated_at' => $timestamp,
            ]);

            return ['products' => $products->all(), 'request_id' => $requestId];
        }, 3);
    }

    private function recoverStaleProducts(int $runId, int $maxAttempts): void
    {
        $minutes = max(5, (int) ceil(max(1, (int) config('inventory_report.timeout_seconds', 60)) * 2 / 60));
        $cutoff = now()->subMinutes($minutes);

        DB::table('stj_inventory_report_products')
            ->where('irp_run_id', $runId)
            ->where('irp_status', 'PROCESSING')
            ->where('irp_last_requested_at', '<', $cutoff)
            ->update([
                'irp_status' => DB::raw("CASE WHEN irp_attempts >= {$maxAttempts} THEN 'FAILED' ELSE 'RETRY_PENDING' END"),
                'irp_last_error' => 'Se recupero un procesamiento interrumpido.',
                'irp_completed_at' => DB::raw("CASE WHEN irp_attempts >= {$maxAttempts} THEN CURRENT_TIMESTAMP ELSE NULL END"),
                'irp_updated_at' => now(),
            ]);

        DB::table('stj_inventory_report_requests')
            ->where('irq_run_id', $runId)
            ->where('irq_status', 'PENDING')
            ->where('irq_started_at', '<', $cutoff)
            ->update([
                'irq_status' => 'FAILED',
                'irq_error' => 'Solicitud interrumpida recuperada por el procesador.',
                'irq_completed_at' => now(),
            ]);
    }

    /** @return array<string, int|string|null> */
    private function refreshRun(int $runId, int $maxAttempts): array
    {
        $counts = DB::table('stj_inventory_report_products')
            ->where('irp_run_id', $runId)
            ->selectRaw("SUM(CASE WHEN irp_status = 'FOUND' THEN 1 ELSE 0 END) AS found")
            ->selectRaw("SUM(CASE WHEN irp_status = 'NOT_RETURNED' THEN 1 ELSE 0 END) AS not_returned")
            ->selectRaw("SUM(CASE WHEN irp_status = 'FAILED' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN irp_status IN ('PENDING', 'RETRY_PENDING', 'PROCESSING') AND irp_attempts < ? THEN 1 ELSE 0 END) AS pending", [$maxAttempts])
            ->first();
        $rows = DB::table('stj_inventory_report_rows')->where('irw_run_id', $runId)->count();
        $pending = (int) ($counts->pending ?? 0);
        $status = $pending > 0
            ? 'PROCESSING'
            : (((int) ($counts->not_returned ?? 0) > 0 || (int) ($counts->failed ?? 0) > 0) ? 'PARTIAL' : 'COMPLETE');
        $timestamp = now();

        DB::table('stj_inventory_report_runs')->where('irr_id', $runId)->update([
            'irr_status' => $status,
            'irr_found_products' => (int) ($counts->found ?? 0),
            'irr_not_returned_products' => (int) ($counts->not_returned ?? 0),
            'irr_failed_products' => (int) ($counts->failed ?? 0),
            'irr_result_rows' => $rows,
            'irr_queries_closed_at' => $pending === 0 ? $timestamp : null,
            'irr_completed_at' => $pending === 0 ? $timestamp : null,
            'irr_updated_at' => $timestamp,
        ]);

        return [
            'status' => $status,
            'found' => (int) ($counts->found ?? 0),
            'notReturned' => (int) ($counts->not_returned ?? 0),
            'failed' => (int) ($counts->failed ?? 0),
            'pending' => $pending,
            'rows' => $rows,
        ];
    }
}
