<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\Dashboard\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentServiceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_paises', function (Blueprint $table): void {
            $table->unsignedInteger('pai_id')->primary();
            $table->string('pai_codigo');
            $table->string('pai_nombre');
        });
        Schema::create('stj_tiendas', function (Blueprint $table): void {
            $table->unsignedInteger('tie_id')->primary();
            $table->unsignedInteger('tie_pais');
            $table->string('tie_codigo');
            $table->string('tie_nombre');
        });

        DB::table('stj_paises')->insert([
            'pai_id' => 1,
            'pai_codigo' => 'SV',
            'pai_nombre' => 'El Salvador',
        ]);
        DB::table('stj_tiendas')->insert([
            ['tie_id' => 55, 'tie_pais' => 1, 'tie_codigo' => 'UTZ', 'tie_nombre' => 'Utz Ulew'],
            ['tie_id' => 73, 'tie_pais' => 1, 'tie_codigo' => 'ESC', 'tie_nombre' => 'EscalÃ³n'],
        ]);

        config()->set('services.stj_citas', [
            'url' => 'http://stj-citas.test',
            'token' => 'token-privado-prueba',
            'timeout' => 8,
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-31 23:30:00', 'America/El_Salvador'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_transforma_citas_al_contrato_historico_y_cuenta_correos(): void
    {
        Http::fake(fn () => Http::response($this->payload([
            $this->row(['id' => 2, 'code' => 'DOS', 'emailStatus' => 'PENDIENTE']),
            $this->row(['id' => 1, 'code' => 'UNO', 'emailStatus' => 'ENVIADO']),
        ]), 200));

        $result = app(AppointmentService::class)->appointments(['country' => 'SV', 'store' => 'UTZ']);

        $this->assertSame(2, $result['summary']['appointments']);
        $this->assertSame(1, $result['summary']['emailsSent']);
        $this->assertSame('UTZ', $result['filters']['store']);
        $this->assertSame(55, $result['filters']['storeId']);
        $this->assertSame([
            'id', 'code', 'dui', 'name', 'phone', 'email', 'registeredAt', 'schedule', 'day', 'emailSent', 'store',
        ], array_keys($result['rows'][0]));
        $this->assertSame('09:30 - 10:30', $result['rows'][0]['schedule']);
        $this->assertSame(['id' => 55, 'code' => 'UTZ', 'name' => 'Utz Ulew', 'country' => 1], $result['rows'][0]['store']);
        $this->assertFalse($result['rows'][0]['emailSent']);
        $this->assertTrue($result['rows'][1]['emailSent']);

        Http::assertSent(fn ($request) => (int) $request['country'] === 1
            && (int) $request['store'] === 55
            && (int) $request['year'] === 2026);
    }

    public function test_acepta_tie_id_y_tie_codigo_y_conserva_la_tienda_solicitada(): void
    {
        Http::fake(function ($request) {
            $storeId = (int) $request['store'];

            return Http::response($this->payload([$this->row(['storeId' => $storeId])]), 200);
        });

        $byId = app(AppointmentService::class)->appointments(['country' => '1', 'store' => '55']);
        $byCode = app(AppointmentService::class)->appointments(['country' => 'SV', 'store' => 'ESC']);

        $this->assertSame(55, $byId['rows'][0]['store']['id']);
        $this->assertSame('UTZ', $byId['rows'][0]['store']['code']);
        $this->assertSame(73, $byCode['rows'][0]['store']['id']);
        $this->assertSame('ESC', $byCode['rows'][0]['store']['code']);
    }

    public function test_tienda_sin_citas_conserva_catalogos_filtros_y_resumen_en_cero(): void
    {
        Http::fake(fn () => Http::response($this->payload([]), 200));

        $result = app(AppointmentService::class)->appointments(['country' => '1', 'store' => '55']);

        $this->assertCount(1, $result['countries']);
        $this->assertCount(2, $result['stores']);
        $this->assertSame(0, $result['summary']['appointments']);
        $this->assertSame(0, $result['summary']['emailsSent']);
        $this->assertSame([], $result['rows']);
    }

    public function test_todas_las_tiendas_conserva_la_tienda_real_de_cada_cita(): void
    {
        Http::fake(fn () => Http::response($this->payload([
            $this->row(['id' => 1, 'storeId' => 55]),
            $this->row(['id' => 2, 'storeId' => 73]),
        ]), 200));

        $result = app(AppointmentService::class)->appointments(['country' => 'SV', 'store' => 'ALL']);

        $this->assertSame(2, $result['summary']['appointments']);
        $this->assertNull($result['filters']['store']);
        $this->assertNull($result['filters']['storeId']);
        $this->assertSame('Todas las tiendas', $result['filters']['storeName']);
        $this->assertSame('Utz Ulew', $result['rows'][0]['store']['name']);
        $this->assertSame(73, $result['rows'][1]['store']['id']);
        $this->assertSame('ESC', $result['rows'][1]['store']['code']);

        Http::assertSent(fn ($request) => ! isset($request['store']));
    }

    public function test_endpoint_dashboard_conserva_contrato_de_error_controlado(): void
    {
        Sanctum::actingAs(new User, ['dashboard']);
        Http::fake(fn () => Http::response([
            'success' => false,
            'message' => 'No existe campaÃ±a.',
            'error' => 'CAMPAIGN_NOT_FOUND',
        ], 404));

        $this->getJson('/api/dashboard/appointments?country=1&store=55')
            ->assertNotFound()
            ->assertExactJson([
                'ok' => false,
                'message' => 'No existe una campana de citas para los filtros seleccionados.',
                'error' => 'CAMPAIGN_NOT_FOUND',
            ]);
    }

    public function test_exporta_todas_las_citas_consultadas_a_xlsx(): void
    {
        Http::fake(fn () => Http::response($this->payload([
            $this->row(['id' => 1, 'storeId' => 55]),
            $this->row(['id' => 2, 'storeId' => 73]),
        ]), 200));

        $export = app(AppointmentService::class)->export(['country' => 'SV', 'store' => 'ALL']);

        $this->assertMatchesRegularExpression('/^citas-1-todas-\d{8}-\d{6}\.xlsx$/', $export['filename']);
        $this->assertStringStartsWith('PK', $export['contents']);
        $this->assertGreaterThan(1000, strlen($export['contents']));
        Http::assertSent(fn ($request) => ! isset($request['store']));
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
}
