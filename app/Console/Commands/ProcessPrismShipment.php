<?php

namespace App\Console\Commands;

use App\Services\Prism\PrismShipmentProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessPrismShipment extends Command
{
    protected $signature = 'prism:process-shipment
        {--shipment= : pe_id de prism_envios}
        {--stj= : Referencia exacta de pago}
        {--order= : ped_id, requiere --payment}
        {--payment= : ppa_id, requiere --order}
        {--execute : Permite escrituras remotas para un único envío}
        {--confirm-ref= : Referencia exacta requerida con --execute}';

    protected $description = 'Valida o procesa manualmente un solo envío HN, sin cron ni selección masiva';

    public function handle(PrismShipmentProcessor $processor): int
    {
        try {
            $shipment = $this->option('shipment');
            $reference = $this->option('stj');
            $order = $this->option('order');
            $payment = $this->option('payment');
            if ((int) ($shipment !== null) + (int) ($reference !== null) + (int) ($order !== null || $payment !== null) !== 1) {
                throw new RuntimeException('Seleccionar únicamente --shipment, --stj o el par --order/--payment.');
            }
            foreach ([$shipment, $order, $payment] as $id) {
                if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1 || (string) (int) $id !== (string) $id)) {
                    throw new RuntimeException('Los IDs deben ser enteros positivos.');
                }
            }
            $query = DB::table('prism_envios as e')->join('stj_paises as p', 'p.pai_id', '=', 'e.pais_codigo')
                ->where('p.pai_codigo', 'HN');
            if ($shipment !== null) {
                $query->where('e.pe_id', (int) $shipment);
            } elseif ($reference !== null) {
                $query->where('e.stj_ref', $reference);
            } else {
                if ($order === null || $payment === null) {
                    throw new RuntimeException('Se requieren ambos: --order y --payment.');
                }
                $query->where('e.ped_id', (int) $order)->where('e.ppa_id', (int) $payment);
            }
            $rows = $query->limit(2)->get(['e.*']);
            if ($rows->count() !== 1) {
                throw new RuntimeException('La selección debe identificar exactamente un envío de Honduras.');
            }
            $row = $rows[0];
            if ($this->option('execute') && (string) $this->option('confirm-ref') !== (string) $row->stj_ref) {
                throw new RuntimeException('Para ejecutar, enviar --confirm-ref con la referencia STJ exacta.');
            }
            $this->line(json_encode($processor->process((int) $row->pe_id, (bool) $this->option('execute')),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error(get_class($exception) === RuntimeException::class ? $exception->getMessage()
                : 'Fallo interno: revisar esquema/configuración del procesador. No se muestran payloads sensibles.');

            return self::FAILURE;
        }
    }
}
