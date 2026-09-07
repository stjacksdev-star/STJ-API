<?php

namespace App\Services\Prism;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class PrismClient
{
    private ?string $token = null;

    /** Read-only entry point. Tokens belong to this execution, never to a browser session. */
    public function activeStores(): array
    {
        $this->validateConfiguration();
        $this->token ??= $this->login();

        $response = $this->get('/v1/rest/store', ['cols' => '*', 'filter' => 'active,eq,true'], $this->token);
        if (in_array($response->status(), [401, 403], true)) {
            $this->token = null;
            $this->token = $this->login();
            $response = $this->get('/v1/rest/store', ['cols' => '*', 'filter' => 'active,eq,true'], $this->token);
        }

        $stores = $this->decode($response, 'tiendas');
        if (! array_is_list($stores)) {
            throw new RuntimeException('Prism: formato inesperado en tiendas.');
        }
        foreach ($stores as $store) {
            if (! is_array($store) || ! isset($store['sid'], $store['store_code'], $store['store_name'])
                || ! is_scalar($store['sid']) || ! is_scalar($store['store_code']) || ! is_string($store['store_name'])) {
                throw new RuntimeException('Prism: tienda sin identificadores válidos.');
            }
        }

        return array_map(fn (array $store) => [
            'sid' => (string) $store['sid'],
            'store_code' => (string) $store['store_code'],
            'store_number' => (string) ($store['store_number'] ?? ''),
            'store_name' => $store['store_name'],
            'active' => $store['active'] ?? null,
        ], $stores);
    }

    public function logout(): void
    {
        $token = $this->token;
        $this->token = null;
        if ($token === null) {
            return;
        }
        $response = $this->get('/api/security/logout', [], $token);
        if (! $response->successful() && ! in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('Prism: cierre de sesión fallido (HTTP '.$response->status().').');
        }
    }

    private function login(): string
    {
        $response = $this->get('/api/security/login', [
            'usr' => config('prism.hn.username'),
            'pwd' => config('prism.hn.password'),
            'ws' => config('prism.hn.workstation'),
        ]);
        $data = $this->decode($response, 'autenticación');
        $token = $data[0]['token'] ?? null;
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Prism: autenticación sin token válido.');
        }

        return trim($token);
    }

    private function validateConfiguration(): void
    {
        foreach (['host', 'username', 'password', 'workstation'] as $key) {
            if (trim((string) config('prism.hn.'.$key)) === '') {
                throw new RuntimeException('Prism: falta configurar prism.hn.'.$key.'.');
            }
        }
        $host = parse_url((string) config('prism.hn.host'));
        if (! is_array($host) || ! in_array($host['scheme'] ?? '', ['http', 'https'], true) || empty($host['host'])
            || isset($host['user']) || isset($host['pass']) || isset($host['query']) || isset($host['fragment'])) {
            throw new RuntimeException('Prism: el host debe ser una URL HTTP o HTTPS con IP o dominio, sin credenciales ni parámetros.');
        }
    }

    private function get(string $path, array $query, ?string $token = null): Response
    {
        try {
            return Http::acceptJson()
                ->connectTimeout(max(1, (int) config('prism.hn.connect_timeout')))
                ->timeout(max(1, (int) config('prism.hn.timeout')))
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->withHeaders($token === null ? [] : ['Auth-Session' => $token])
                ->get(rtrim((string) config('prism.hn.host'), '/').$path, $query);
        } catch (ConnectionException) {
            // The original exception may contain the login URL and its credentials.
            throw new RuntimeException('Prism: conexión fallida o tiempo de espera agotado.');
        }
    }

    private function decode(Response $response, string $operation): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Prism: error de '.$operation.' (HTTP '.$response->status().').');
        }
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new RuntimeException('Prism: JSON inválido en '.$operation.'.');
        }
        if (! is_array($data)) {
            throw new RuntimeException('Prism: respuesta inesperada en '.$operation.'.');
        }

        return $data;
    }
}
