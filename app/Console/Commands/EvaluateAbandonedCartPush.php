<?php

namespace App\Console\Commands;

use App\Services\AbandonedCartPushEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class EvaluateAbandonedCartPush extends Command
{
    protected $signature = 'push:web-evaluate
        {--scenario=ABANDONED_CART : Escenario que se evaluara}
        {--limit=500 : Maximo de carritos por ejecucion}
        {--at= : Fecha de evaluacion ISO-8601, solo para pruebas controladas}
        {--dry-run : Detecta candidatos sin crear entregas}';

    protected $description = 'Detecta candidatos Push Web y crea entregas idempotentes sin enviarlas';

    public function handle(AbandonedCartPushEvaluator $evaluator): int
    {
        if (strtoupper((string) $this->option('scenario')) !== AbandonedCartPushEvaluator::AUTOMATION_CODE) {
            $this->error('En esta fase solamente esta disponible ABANDONED_CART.');

            return self::INVALID;
        }

        try {
            $at = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at')) : null;
            $summary = $evaluator->evaluate($at, (int) $this->option('limit'), (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error('No fue posible evaluar carritos abandonados: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($summary['automation_missing'] > 0) {
            $this->warn('ABANDONED_CART no existe o no esta ACTIVA en stj_push_automatizaciones.');

            return self::SUCCESS;
        }

        $this->table(['Resultado', 'Cantidad'], collect($summary)->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->info($this->option('dry-run') ? 'Evaluacion terminada sin escribir entregas.' : 'Evaluacion terminada; no se envio ninguna notificacion.');

        return self::SUCCESS;
    }
}
