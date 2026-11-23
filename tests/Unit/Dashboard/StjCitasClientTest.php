<?php

namespace Tests\Unit\Dashboard;

use App\Services\Dashboard\Exceptions\StjCitasIntegrationException;
use App\Services\Dashboard\StjCitasClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StjCitasClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.stj_citas', [
            'url' => 'http://stj-citas.test',
            'token' => 'token-privado-prueba',
            'timeout' => 8,
        ]);
    }

    public function test_envia_token_parametros_y_acepta_respuesta_valida(): void
    {
        Http::fake([
            'stj-citas.test/*' => Http::response($this->payload([$this->row()]), 200),
        ]);

        $data = app(StjCitasClient::class)->appointments(1, 55, 2026);

        $this->assertCount(1, $data['rows']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token-privado-prueba')
            && $request->hasHeader('Accept', 'application/json')
            && str_contains($request->url(), '/api/integraciones/dashboard/citas')
            && (int) $request['country'] === 1
            && (int) $request['store'] === 55
            && (int) $request['year'] === 2026);
    }

    public function test_configuracion_faltante_es_error_controlado(): void
    {
        config()->set('services.stj_citas.token', '');
        Http::fake();

        $this->expectIntegrationError(503, 'INTEGRATION_NOT_CONFIGURED', fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
        Http::assertNothingSent();
    }

    public function test_consulta_todas_las_tiendas_sin_enviar_store(): void
    {
        Http::fake([
            'stj-citas.test/*' => Http::response($this->payload([
                $this->row(['storeId' => 55]),
                $this->row(['id' => 2, 'storeId' => 73]),
            ]), 200),
        ]);

        $data = app(StjCitasClient::class)->appointments(1, null, 2026);

        $this->assertCount(2, $data['rows']);
        Http::assertSent(fn ($request) => (int) $request['country'] === 1
            && ! isset($request['store'])
            && (int) $request['year'] === 2026);
    }

    public function test_timeout_y_error_de_conexion_se_distinguen(): void
    {
        Http::fakeSequence()
            ->pushFailedConnection('Operation timed out')
            ->pushFailedConnection('Connection refused');

        $this->expectIntegrationError(504, 'INTEGRATION_TIMEOUT', fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
        $this->expectIntegrationError(503, 'INTEGRATION_UNAVAILABLE', fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
    }

    public function test_mapea_errores_http_sin_exponer_respuesta_externa(): void
    {
        $cases = [
            401 => [503, 'INTEGRATION_UNAUTHORIZED'],
            404 => [404, 'CAMPAIGN_NOT_FOUND'],
            409 => [409, 'AMBIGUOUS_CAMPAIGN'],
            500 => [502, 'INTEGRATION_BAD_GATEWAY'],
            503 => [503, 'INTEGRATION_UNAVAILABLE'],
        ];

        $sequence = Http::fakeSequence();
        foreach (array_keys($cases) as $remoteStatus) {
            $sequence->push([
                'success' => false,
                'message' => 'Detalle interno que no debe propagarse',
                'token' => 'secreto',
            ], $remoteStatus);
        }

        foreach ($cases as $remoteStatus => [$publicStatus, $code]) {
            $this->expectIntegrationError($publicStatus, $code, fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
        }
    }

    public function test_distingue_filtros_invalidos_de_campana_inexistente(): void
    {
        Http::fakeSequence()
            ->push(['success' => false, 'error' => 'STORE_NOT_FOUND'], 404)
            ->push(['success' => false, 'error' => 'CAMPAIGN_NOT_FOUND'], 404);

        $this->expectIntegrationError(422, 'INVALID_APPOINTMENT_FILTERS', fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
        $this->expectIntegrationError(404, 'CAMPAIGN_NOT_FOUND', fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
    }

    public function test_rechaza_json_invalido_respuesta_incompleta_e_ids_inconsistentes(): void
    {
        Http::fakeSequence()
            ->push('no-json', 200, ['Content-Type' => 'text/plain'])
            ->push(['success' => true, 'data' => ['rows' => []]], 200)
            ->push($this->payload([$this->row(['storeId' => 999])]), 200);

        for ($case = 0; $case < 3; $case++) {
            $this->expectIntegrationError(502, 'INTEGRATION_INVALID_RESPONSE', fn () => app(StjCitasClient::class)->appointments(1, 55, 2026));
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function payload(array $rows): array
    {
        return [
            'success' => true,
            'data' => [
                'campaign' => ['id' => 10, 'name' => 'Citas 2026', 'year' => 2026],
                'rows' => $rows,
            ],
        ];
    }

    /** @param array<string, mixed> $changes */
    private function row(array $changes = []): array
    {
        return array_replace([
            'id' => 1,
            'code' => 'STJ-1',
            'document' => 'DOC-1',
            'name' => 'Ana LÃ³pez',
            'phone' => '7000-0000',
            'email' => 'ana@example.com',
            'registeredAt' => '2026-11-22 08:00:00',
            'date' => '2026-11-24',
            'startTime' => '09:30',
            'endTime' => '10:30',
            'emailStatus' => 'ENVIADO',
            'status' => 'CONFIRMADA',
            'storeId' => 55,
            'countryId' => 1,
        ], $changes);
    }

    private function expectIntegrationError(int $status, string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una excepciÃ³n controlada de integraciÃ³n.');
        } catch (StjCitasIntegrationException $exception) {
            $this->assertSame($status, $exception->httpStatus);
            $this->assertSame($code, $exception->errorCode);
            $this->assertStringNotContainsString('secreto', $exception->getMessage());
        }
    }
}
