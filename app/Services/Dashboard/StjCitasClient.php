<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Exceptions\StjCitasIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class StjCitasClient
{
    /** @return array{campaign: array<string, mixed>, rows: array<int, array<string, mixed>>} */
    public function appointments(int $countryId, ?int $storeId, int $year): array
    {
        $url = rtrim(trim((string) config('services.stj_citas.url')), '/');
        $token = trim((string) config('services.stj_citas.token'));
        $timeout = max(1, (int) config('services.stj_citas.timeout', 8));

        if ($url === '' || $token === '') {
            throw new StjCitasIntegrationException('La integracion de citas no esta configurada.', 503, 'INTEGRATION_NOT_CONFIGURED');
        }

        if (app()->isProduction() && ! str_starts_with(strtolower($url), 'https://')) {
            throw new StjCitasIntegrationException('La integracion de citas no esta disponible.', 503, 'INTEGRATION_INSECURE_URL');
        }

        try {
            $query = [
                'country' => $countryId,
                'year' => $year,
            ];

            if ($storeId !== null) {
                $query['store'] = $storeId;
            }

            $response = Http::baseUrl($url)->withToken($token)->acceptJson()
                ->connectTimeout(min(3, $timeout))->timeout($timeout)
                ->get('/api/integraciones/dashboard/citas', $query);
        } catch (ConnectionException $exception) {
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out')
                || str_contains(strtolower($exception->getMessage()), 'timeout');

            throw new StjCitasIntegrationException(
                $timedOut ? 'La consulta de citas excedio el tiempo de espera.' : 'No fue posible conectar con el servicio de citas.',
                $timedOut ? 504 : 503,
                $timedOut ? 'INTEGRATION_TIMEOUT' : 'INTEGRATION_UNAVAILABLE',
                $exception,
            );
        }

        if (! $response->successful()) {
            throw $this->httpException($response);
        }

        $payload = $response->json();
        if (! is_array($payload)
            || ($payload['success'] ?? null) !== true
            || ! is_array($payload['data'] ?? null)
            || ! is_array($payload['data']['campaign'] ?? null)
            || ! is_array($payload['data']['rows'] ?? null)
            || ! array_is_list($payload['data']['rows'])) {
            throw $this->invalidResponse();
        }

        $campaign = $payload['data']['campaign'];
        if (! isset($campaign['id'], $campaign['name'], $campaign['year'])
            || ! is_numeric($campaign['id'])
            || ! is_string($campaign['name'])
            || ! is_numeric($campaign['year'])
            || (int) $campaign['year'] !== $year) {
            throw $this->invalidResponse();
        }

        foreach ($payload['data']['rows'] as $row) {
            if (! $this->validRow($row, $countryId, $storeId)) {
                throw $this->invalidResponse();
            }
        }

        return $payload['data'];
    }

    private function httpException(Response $response): StjCitasIntegrationException
    {
        $status = $response->status();
        $remoteCode = is_string($response->json('error')) ? $response->json('error') : null;

        if ($status === 404 && in_array($remoteCode, ['COUNTRY_NOT_FOUND', 'STORE_NOT_FOUND'], true)) {
            return new StjCitasIntegrationException('El pais o la tienda enviados al servicio de citas no son validos.', 422, 'INVALID_APPOINTMENT_FILTERS');
        }

        return match ($status) {
            404 => new StjCitasIntegrationException('No existe una campana de citas para los filtros seleccionados.', 404, 'CAMPAIGN_NOT_FOUND'),
            409 => new StjCitasIntegrationException('La configuracion de campanas de citas es ambigua.', 409, 'AMBIGUOUS_CAMPAIGN'),
            422 => new StjCitasIntegrationException('El pais o la tienda enviados al servicio de citas no son validos.', 422, 'INVALID_APPOINTMENT_FILTERS'),
            503 => new StjCitasIntegrationException('El servicio de citas no esta disponible temporalmente.', 503, 'INTEGRATION_UNAVAILABLE'),
            401, 403 => new StjCitasIntegrationException('La integracion de citas no esta autorizada.', 503, 'INTEGRATION_UNAUTHORIZED'),
            default => new StjCitasIntegrationException('El servicio de citas devolvio una respuesta inesperada.', 502, 'INTEGRATION_BAD_GATEWAY'),
        };
    }

    private function invalidResponse(): StjCitasIntegrationException
    {
        return new StjCitasIntegrationException('El servicio de citas devolvio una respuesta invalida.', 502, 'INTEGRATION_INVALID_RESPONSE');
    }

    private function validRow(mixed $row, int $countryId, ?int $storeId): bool
    {
        if (! is_array($row)) {
            return false;
        }

        $strings = ['code', 'document', 'name', 'phone', 'email', 'date', 'startTime', 'endTime', 'emailStatus', 'status'];
        foreach ($strings as $field) {
            if (! array_key_exists($field, $row) || ! is_string($row[$field])) {
                return false;
            }
        }

        return isset($row['id'], $row['storeId'], $row['countryId'])
            && is_numeric($row['id'])
            && is_numeric($row['storeId'])
            && is_numeric($row['countryId'])
            && ($storeId === null || (int) $row['storeId'] === $storeId)
            && (int) $row['countryId'] === $countryId
            && $row['status'] === 'CONFIRMADA'
            && array_key_exists('registeredAt', $row)
            && ($row['registeredAt'] === null || is_string($row['registeredAt']));
    }
}
