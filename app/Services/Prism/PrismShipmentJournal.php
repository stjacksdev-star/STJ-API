<?php

namespace App\Services\Prism;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PrismShipmentJournal
{
    public array $checkpoint;

    public function __construct(public readonly int $id, ?string $checkpoint)
    {
        $this->checkpoint = $checkpoint ? json_decode($checkpoint, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function save(array $changes = []): void
    {
        DB::table('prism_envios')->where('pe_id', $this->id)->update(array_merge($changes, [
            'processing_checkpoint' => json_encode($this->checkpoint, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]));
    }

    public function log(
        string $step,
        string $status,
        ?int $http = null,
        ?string $path = null,
        ?array $requestPayload = null,
    ): void {
        // Persist only the JSON body sent to Prism. Authentication query parameters,
        // Auth-Session and other headers never reach this journal.
        DB::table('prism_envios_log')->insert(['pe_id' => $this->id, 'step' => $step,
            'http_code' => $http, 'request_url' => $path,
            'request_payload' => $requestPayload === null ? null : json_encode(
                $requestPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ),
            'error_message' => $status, 'created_at' => now()]);
    }

    public function read(PrismClient $client, string $step, string $path, array $query = []): array
    {
        try {
            $data = $client->request('GET', $path, $query);
            $this->log($step, 'OK', $client->lastStatus, $path);

            return $data;
        } catch (\Throwable $exception) {
            $this->log($step, 'ERROR', $client->lastStatus, $path);
            throw $exception;
        }
    }

    /** Persist intent before network I/O. A retry must reconcile the uncertain step first. */
    public function write(PrismClient $client, string $step, string $method, string $path, array $body, array $query = []): array
    {
        if (isset($this->checkpoint['uncertain'])) {
            throw new RuntimeException('Escritura previa sin reconciliar: '.$this->checkpoint['uncertain']);
        }
        $this->checkpoint['uncertain'] = $step;
        $this->save();
        $this->log($step, 'START', null, $path, $body);
        try {
            $response = $client->request($method, $path, $query, $body);
            $this->log($step, 'HTTP_OK_VERIFY_REQUIRED', $client->lastStatus, $path);

            return $response;
        } catch (\Throwable $exception) {
            $this->log($step, 'ERROR_RECONCILIATION_REQUIRED', $client->lastStatus, $path);
            if (in_array($client->lastStatus, [401, 403, 409, 422], true)) {
                // Explicit authorization/version/validation rejection, not an ambiguous timeout.
                unset($this->checkpoint['uncertain']);
                $this->save();
            }
            throw $exception;
        }
    }

    public function verified(string $step, array $changes = []): void
    {
        if (($this->checkpoint['uncertain'] ?? null) === $step) {
            unset($this->checkpoint['uncertain']);
        }
        $this->checkpoint['completed'][$step] = true;
        $this->save($changes);
        $this->log($step, 'VERIFIED');
    }
}
