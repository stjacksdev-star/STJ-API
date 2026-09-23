<?php

namespace App\Console\Commands;

use App\Services\InventoryReport\Exceptions\InventoryReportRequestException;
use App\Services\InventoryReport\InventoryReportClient;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ProbeInventoryReport extends Command
{
    protected $signature = 'inventory-report:probe
        {country : Codigo de pais: SV, GT, CR, PA o HN}
        {--code=* : Codigo de producto; puede repetirse}
        {--store=* : Tienda opcional; puede repetirse}';

    protected $description = 'Prueba en modo lectura el contrato del endpoint del reporte de inventario';

    public function handle(InventoryReportClient $client): int
    {
        $country = strtoupper(trim((string) $this->argument('country')));
        $codes = array_values((array) $this->option('code'));
        $stores = array_values((array) $this->option('store'));

        if ($codes === []) {
            $this->error('Debe indicar al menos un --code.');

            return self::INVALID;
        }
        if (count($codes) > 10) {
            $this->error('El diagnostico acepta como maximo 10 codigos por ejecucion.');

            return self::INVALID;
        }

        try {
            $result = $client->fetch($country, $codes, $stores === [] ? null : $stores);
        } catch (InvalidArgumentException|InventoryReportRequestException $exception) {
            $this->error($exception->getMessage());

            if ($exception instanceof InventoryReportRequestException) {
                $this->line('HTTP: '.($exception->httpStatus ?? 'sin respuesta').' | Duracion: '.($exception->durationMs ?? 0).' ms');
            }

            return self::FAILURE;
        }

        $this->info("Contrato {$country} validado correctamente.");
        $this->line("Adaptador: {$result->adapter} | HTTP: {$result->httpStatus} | Duracion: {$result->durationMs} ms");
        $this->line('Filas validas: '.count($result->response->rows));
        $this->line('Productos devueltos: '.count($result->response->returnedCodes));
        $this->line('Productos no devueltos: '.count($result->response->notReturnedCodes));

        if ($result->response->rows !== []) {
            $this->table(
                ['ESTILO', 'TIENDA', 'TALLA', 'EXISTENCIA', 'PRECIO VTA'],
                array_map(static fn (array $row): array => [
                    $row['code'], $row['store'], $row['size'], $row['quantity'], $row['sale_price'],
                ], array_slice($result->response->rows, 0, 20)),
            );
        }
        if ($result->response->notReturnedCodes !== []) {
            $this->warn('No devueltos: '.implode(', ', $result->response->notReturnedCodes));
        }
        foreach ($result->response->warnings as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
