<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrismStoresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'sqlite') {
            throw new \RuntimeException('Prism tests require testing and SQLite.');
        }
        config(['prism.hn.host' => 'https://prism.example', 'prism.hn.username' => 'test-user',
            'prism.hn.password' => 'secret-test', 'prism.hn.workstation' => 'test-ws']);
        Http::preventStrayRequests();
    }

    public function test_command_reads_stores_preserving_identifiers_and_closes_session(): void
    {
        Http::fake([
            '*/api/security/login*' => Http::response([['token' => 'test-token']]),
            '*/v1/rest/store*' => Http::response([['sid' => '761346871000100960', 'store_code' => '002', 'store_number' => '2', 'store_name' => 'Tienda HN', 'active' => true]]),
            '*/api/security/logout*' => Http::response('', 200),
        ]);
        $this->artisan('prism:stores --json')
            ->expectsOutput('{"country":"HN","count":1,"stores":[{"sid":"761346871000100960","store_code":"002","store_number":"2","store_name":"Tienda HN","active":true}]}')
            ->assertSuccessful();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/rest/store')
            && $request->method() === 'GET' && $request['cols'] === '*'
            && $request['filter'] === 'active,eq,true' && $request->hasHeader('Auth-Session', 'test-token'));
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_expired_session_is_renewed_once_and_empty_list_is_success(): void
    {
        Http::fake([
            '*/api/security/login*' => Http::sequence()->push([['token' => 'old']])->push([['token' => 'new']]),
            '*/v1/rest/store*' => Http::sequence()->push([], 401)->push([]),
            '*/api/security/logout*' => Http::response('', 200),
        ]);
        $this->artisan('prism:stores --json')->expectsOutput('{"country":"HN","count":0,"stores":[]}')->assertSuccessful();
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/rest/store') && $request->hasHeader('Auth-Session', 'new'));
    }

    public function test_repeated_denial_stops_after_one_retry(): void
    {
        Http::fake([
            '*/api/security/login*' => Http::response([['token' => 'token']]),
            '*/v1/rest/store*' => Http::response([], 403),
            '*/api/security/logout*' => Http::response('', 200),
        ]);
        $this->artisan('prism:stores')->expectsOutput('Prism: error de tiendas (HTTP 403).')->assertFailed();
        Http::assertSentCount(5);
    }

    public function test_invalid_json_fails_and_still_closes_session(): void
    {
        Http::fake([
            '*/api/security/login*' => Http::response([['token' => 'token']]),
            '*/v1/rest/store*' => Http::response('<html>error</html>'),
            '*/api/security/logout*' => Http::response('', 200),
        ]);
        $this->artisan('prism:stores')->expectsOutput('Prism: JSON inválido en tiendas.')->assertFailed();
        Http::assertSentCount(3);
    }

    public function test_connection_failure_has_a_sanitized_message(): void
    {
        Http::fake(['*' => Http::failedConnection('secret-test in URL')]);
        $this->artisan('prism:stores')->expectsOutput('Prism: conexión fallida o tiempo de espera agotado.')->assertFailed();
    }

    public function test_missing_configuration_and_unsupported_country_make_no_requests(): void
    {
        Http::fake();
        $this->artisan('prism:stores --country=SV')->assertFailed();
        config(['prism.hn.password' => '']);
        $this->artisan('prism:stores')->expectsOutput('Prism: falta configurar prism.hn.password.')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_http_ip_and_port_are_preserved_for_the_entire_session(): void
    {
        config(['prism.hn.host' => 'http://192.0.2.10:8080/']);
        Http::fake([
            'http://192.0.2.10:8080/api/security/login*' => Http::response([['token' => 'token']]),
            'http://192.0.2.10:8080/v1/rest/store*' => Http::response([]),
            'http://192.0.2.10:8080/api/security/logout*' => Http::response('', 200),
        ]);
        $this->artisan('prism:stores --json')->expectsOutput('{"country":"HN","count":0,"stores":[]}')->assertSuccessful();
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => ! str_starts_with($request->url(), 'http://192.0.2.10:8080/'));
    }

    public function test_http_error_does_not_expose_response_body(): void
    {
        Http::fake(['*' => Http::response('secret-test', 500)]);
        $this->artisan('prism:stores')->expectsOutput('Prism: error de autenticación (HTTP 500).')->assertFailed();
    }
}
