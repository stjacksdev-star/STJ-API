<?php

namespace App\Console\Commands;

use App\Services\Prism\PrismClient;
use Illuminate\Console\Command;
use RuntimeException;

class PrismStores extends Command
{
    protected $signature = 'prism:stores {--country=HN : País de la integración} {--json : Salida JSON}';

    protected $description = 'Consulta tiendas activas de Retail Prism sin modificar datos comerciales';

    public function handle(PrismClient $client): int
    {
        if (strtoupper((string) $this->option('country')) !== 'HN') {
            $this->error('Prism: solamente Honduras (HN) está habilitado.');

            return self::FAILURE;
        }
        try {
            $stores = $client->activeStores();
            if ($this->option('json')) {
                $this->line(json_encode(['country' => 'HN', 'count' => count($stores), 'stores' => $stores], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['SID', 'Código', 'Número', 'Tienda'], array_map(fn ($store) => [
                    $store['sid'], $store['store_code'], $store['store_number'], $store['store_name'],
                ], $stores));
                $this->info(count($stores).' tiendas recibidas.');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            try {
                $client->logout();
            } catch (RuntimeException $exception) {
                // Keep JSON stdout parseable and do not hide a successful read.
                $this->getOutput()->getErrorStyle()->warning($exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
