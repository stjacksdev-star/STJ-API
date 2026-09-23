<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeInventoryReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('inventory_report.countries.SV.url', 'https://inventory.test/sv');
        config()->set('inventory_report.countries.SV.token', 'sv-secret');
    }

    public function test_probe_validates_the_contract_without_database_writes(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://inventory.test/sv' => Http::response(['datos' => [[
            'estilo' => 'P001',
            'tienda' => '019',
            'talla' => 'M',
            'existencia' => '8',
            'PRECIO' => '24.99',
        ]]])]);

        $this->artisan('inventory-report:probe', ['country' => 'SV', '--code' => ['P001']])
            ->expectsOutputToContain('Contrato SV validado correctamente.')
            ->expectsOutputToContain('Filas validas: 1')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sv-secret')
            && $request['Pais'] === '1'
            && $request['Codigos'] === "'P001'");
    }

    public function test_probe_requires_a_product_code(): void
    {
        Http::fake();

        $this->artisan('inventory-report:probe', ['country' => 'SV'])
            ->expectsOutputToContain('Debe indicar al menos un --code.')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_probe_reports_http_errors_without_printing_the_response_body(): void
    {
        Http::fake(['https://inventory.test/sv' => Http::response(['secret' => 'must-not-be-printed'], 503)]);

        $this->artisan('inventory-report:probe', ['country' => 'SV', '--code' => ['P001']])
            ->expectsOutputToContain('respondio HTTP 503')
            ->doesntExpectOutputToContain('must-not-be-printed')
            ->assertFailed();
    }
}
