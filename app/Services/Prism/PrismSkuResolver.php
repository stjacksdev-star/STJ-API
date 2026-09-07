<?php

namespace App\Services\Prism;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PrismSkuResolver
{
    public function resolve(int $countryId, string $storeCode, array $lines): array
    {
        $url = (string) config('prism.hn.sku_url');
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ! filled(config('prism.hn.sku_token'))) {
            throw new RuntimeException('Configurar PRISM_HN_SKU_URL y PRISM_HN_SKU_TOKEN para resolver artículos.');
        }
        $codes = [];
        foreach ($lines as $line) {
            $sku = $line['sku'];
            if (! preg_match('#^[a-zA-Z0-9_/ .-]+$#D', $sku)) {
                throw new RuntimeException('SKU no compatible con el contrato del resolver legacy.');
            }
            $codes[] = "'".$sku."'";
        }
        try {
            $response = Http::acceptJson()->withToken((string) config('prism.hn.sku_token'))
                ->connectTimeout(max(1, (int) config('prism.hn.connect_timeout')))
                ->timeout(max(1, (int) config('prism.hn.timeout')))
                ->withOptions(['verify' => true, 'allow_redirects' => false])
                ->post($url, ['Pais' => (string) $countryId, 'Codigos' => implode(', ', $codes), 'Tiendas' => $storeCode]);
        } catch (ConnectionException) {
            throw new RuntimeException('Resolver SKU: conexión fallida o timeout.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Resolver SKU: HTTP '.$response->status().'.');
        }
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new RuntimeException('Resolver SKU: JSON inválido.');
        }
        if (! in_array($data['RESULTADO'] ?? null, [true, 1, '1'], true) || ! is_array($data['datos'] ?? null)) {
            throw new RuntimeException('Resolver SKU: respuesta sin resultado válido.');
        }
        $mapped = [];
        foreach ($data['datos'] as $item) {
            $sku = $item['sku_art'] ?? '';
            $line = $lines[$sku] ?? null;
            if (! $line || isset($mapped[$sku]) || (string) ($item['estilo'] ?? '') !== $line['style']
                || (string) ($item['talla'] ?? '') !== $line['size'] || ! preg_match('/^[1-9][0-9]*$/D', (string) ($item['sid'] ?? ''))) {
                throw new RuntimeException('Resolver SKU: artículo duplicado, inesperado o sin SID válido.');
            }
            $mapped[$sku] = array_merge($line, ['sid' => (string) $item['sid']]);
        }
        if (count($mapped) !== count($lines)) {
            throw new RuntimeException('Resolver SKU: faltan artículos; no se creará el documento.');
        }
        if (count(array_unique(array_column($mapped, 'sid'))) !== count($mapped)) {
            throw new RuntimeException('Resolver SKU: un SID corresponde a más de un SKU.');
        }

        return array_values($mapped);
    }
}
