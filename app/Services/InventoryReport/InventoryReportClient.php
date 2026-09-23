<?php

namespace App\Services\InventoryReport;

use App\Services\InventoryReport\Data\InventoryReportHttpResult;
use App\Services\InventoryReport\Exceptions\InventoryReportRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class InventoryReportClient
{
    public function __construct(private readonly InventoryReportAdapterResolver $adapters) {}

    /**
     * Ejecuta exactamente una solicitud. Los reintentos auditables pertenecen al
     * procesador de corridas y no se ocultan dentro del transporte HTTP.
     *
     * @param  array<int, string>  $productCodes
     * @param  array<int, string>|null  $storeCodes
     */
    public function fetch(string $countryCode, array $productCodes, ?array $storeCodes = null): InventoryReportHttpResult
    {
        $countryCode = strtoupper(trim($countryCode));
        $country = config("inventory_report.countries.{$countryCode}");
        if (! is_array($country)) {
            throw new InvalidArgumentException("Pais no soportado para el reporte: {$countryCode}.");
        }

        $codes = $this->cleanValues($productCodes);
        if ($codes === []) {
            throw new InvalidArgumentException('Debe indicar al menos un codigo de producto.');
        }

        $url = trim((string) ($country['url'] ?? ''));
        $token = trim((string) ($country['token'] ?? ''));
        $adapterName = trim((string) ($country['adapter'] ?? ''));
        if ($url === '') {
            throw new InvalidArgumentException("No esta configurado el endpoint del reporte para {$countryCode}.");
        }
        if ($token === '') {
            throw new InvalidArgumentException("No esta configurado el token del reporte para {$countryCode}.");
        }

        $stores = $storeCodes === null
            ? $this->cleanValues((array) ($country['stores'] ?? []))
            : $this->cleanValues($storeCodes);
        $adapter = $this->adapters->resolve($adapterName);
        $requestPayload = $adapter->payload((int) $country['id'], $countryCode, $codes, $stores);
        $startedAt = hrtime(true);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(max(1, (int) config('inventory_report.connect_timeout_seconds', 10)))
                ->timeout(max(1, (int) config('inventory_report.timeout_seconds', 60)))
                ->post($url, $requestPayload);
        } catch (ConnectionException $exception) {
            throw new InventoryReportRequestException(
                message: "No fue posible conectar con el endpoint de inventario de {$countryCode}.",
                durationMs: $this->elapsedMilliseconds($startedAt),
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new InventoryReportRequestException(
                message: "Fallo inesperado al consultar inventario de {$countryCode}.",
                durationMs: $this->elapsedMilliseconds($startedAt),
                previous: $exception,
            );
        }

        $duration = $this->elapsedMilliseconds($startedAt);
        if (! $response->successful()) {
            throw new InventoryReportRequestException(
                message: "El endpoint de inventario de {$countryCode} respondio HTTP {$response->status()}.",
                httpStatus: $response->status(),
                durationMs: $duration,
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new InventoryReportRequestException(
                message: "El endpoint de inventario de {$countryCode} no devolvio JSON valido.",
                httpStatus: $response->status(),
                durationMs: $duration,
            );
        }

        try {
            $normalized = $adapter->normalize($payload, $countryCode, $codes);
        } catch (Throwable $exception) {
            throw new InventoryReportRequestException(
                message: "La respuesta de inventario de {$countryCode} no cumple el contrato esperado: {$exception->getMessage()}",
                httpStatus: $response->status(),
                durationMs: $duration,
                previous: $exception,
            );
        }

        return new InventoryReportHttpResult(
            response: $normalized,
            httpStatus: $response->status(),
            durationMs: $duration,
            endpoint: $url,
            adapter: $adapterName,
        );
    }

    /** @param array<int, mixed> $values */
    private function cleanValues(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
