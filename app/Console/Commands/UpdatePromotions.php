<?php

namespace App\Console\Commands;

use App\Services\PromotionLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class UpdatePromotions extends Command
{
    protected $signature = 'promotions:update
        {--dry-run : Muestra candidatos y transiciones sin escribir estados}
        {--at= : Fecha de referencia opcional, destinada a validacion controlada}';

    protected $description = 'Actualiza estados y horarios del ciclo de vida de promociones';

    public function handle(PromotionLifecycleService $lifecycle): int
    {
        $authority = strtolower(trim((string) config('promotions.lifecycle_authority', 'legacy')));

        if (! in_array($authority, ['legacy', 'stj-api', 'disabled'], true)) {
            $this->error("Autoridad de promociones no valida: {$authority}.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run') || $authority !== 'stj-api';
        $reference = $this->referenceTime();
        if ($reference === false) {
            return self::FAILURE;
        }

        $summary = $lifecycle->process($reference, $dryRun);
        $summary['authority'] = $authority;
        $summary['writesEnabled'] = ! $dryRun;

        $this->renderSummary($summary);
        Log::info('Ciclo de vida de promociones procesado.', $summary);

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function referenceTime(): Carbon|false|null
    {
        $value = trim((string) $this->option('at'));
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, (string) config('promotions.timezone', 'America/El_Salvador'));
        } catch (\Throwable) {
            $this->error('El valor de --at no es una fecha valida.');

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->line('Hora de referencia: '.$summary['referenceTime'].' '.$summary['timezone']);
        $this->line('Autoridad: '.$summary['authority']);
        $this->line('Escrituras habilitadas: '.($summary['writesEnabled'] ? 'SI' : 'NO'));

        foreach ($summary['candidates'] as $type => $ids) {
            $this->line("Candidatos {$type}: ".count($ids).($ids === [] ? '' : ' ['.implode(', ', $ids).']'));
        }

        $this->line('Transiciones: '.count($summary['transitions']));
        foreach ($summary['transitions'] as $transition) {
            $this->line(sprintf(
                '  #%d %s: %s -> %s',
                $transition['promotionId'],
                $transition['type'],
                $transition['from'],
                $transition['to'],
            ));
        }

        $this->line('Omitidos: '.count($summary['skipped']));
        $this->line('Errores: '.count($summary['errors']));
    }
}
