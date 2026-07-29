<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PromotionLifecycleService
{
    /**
     * @return array<string, mixed>
     */
    public function process(?Carbon $reference = null, bool $dryRun = false): array
    {
        $reference = ($reference ?? Carbon::now($this->timezone()))->copy()->setTimezone($this->timezone());
        $promotionIds = $this->candidatePromotionIds($reference);
        $summary = $this->emptySummary($reference, $dryRun);

        foreach ($promotionIds as $promotionId) {
            try {
                $result = $dryRun
                    ? $this->evaluatePromotion((int) $promotionId, $reference, false)
                    : DB::transaction(fn () => $this->evaluatePromotion((int) $promotionId, $reference, true), 3);

                $this->mergeResult($summary, $result);
            } catch (Throwable $exception) {
                report($exception);
                $summary['errors'][] = [
                    'promotionId' => (int) $promotionId,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @return array<int, int>
     */
    private function candidatePromotionIds(Carbon $reference): array
    {
        $at = $reference->format('Y-m-d H:i:s');

        return DB::table('stj_promociones as p')
            ->join('stj_promociones_horario as h', 'h.pho_promocion', '=', 'p.prm_id')
            ->whereIn('p.prm_estado', ['PENDIENTE', 'EN-PROCESO', 'SUSPENDIDO'])
            ->where(function ($query) use ($at) {
                $query
                    ->where(function ($normal) use ($at) {
                        $normal->where('h.pho_tipo', 'NORMAL')
                            ->where(function ($dates) use ($at) {
                                $dates->where('h.pho_inicio', '<=', $at)
                                    ->orWhere('h.pho_fin', '<=', $at);
                            });
                    })
                    ->orWhere(function ($suspension) use ($at) {
                        $suspension->where('h.pho_tipo', 'SUSPENDER')
                            ->where(function ($dates) use ($at) {
                                $dates->where('h.pho_inicio', '<=', $at)
                                    ->orWhere('h.pho_fin', '<=', $at);
                            });
                    });
            })
            ->distinct()
            ->orderBy('p.prm_id')
            ->pluck('p.prm_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluatePromotion(int $promotionId, Carbon $reference, bool $write): array
    {
        $promotionQuery = DB::table('stj_promociones')->where('prm_id', $promotionId);
        $promotion = $write ? $promotionQuery->lockForUpdate()->first() : $promotionQuery->first();

        if (! $promotion) {
            return $this->skipped($promotionId, 'La promocion ya no existe.');
        }

        $scheduleQuery = DB::table('stj_promociones_horario')
            ->where('pho_promocion', $promotionId)
            ->orderBy('pho_inicio')
            ->orderBy('pho_id');
        $schedules = $write ? $scheduleQuery->lockForUpdate()->get() : $scheduleQuery->get();
        $normal = $schedules
            ->where('pho_tipo', 'NORMAL')
            ->sortByDesc(fn ($schedule) => sprintf('%s:%020d', $schedule->pho_inicio, $schedule->pho_id))
            ->first();

        if (! $normal) {
            return $this->skipped($promotionId, 'No tiene horario NORMAL.');
        }

        $at = $reference->format('Y-m-d H:i:s');
        $normalStarted = $normal->pho_inicio !== null && (string) $normal->pho_inicio <= $at;
        $normalEnded = $normal->pho_fin !== null && (string) $normal->pho_fin <= $at;
        $currentSuspension = $schedules
            ->where('pho_tipo', 'SUSPENDER')
            ->filter(fn ($schedule) => $schedule->pho_inicio !== null
                && (string) $schedule->pho_inicio <= $at
                && $schedule->pho_fin !== null
                && (string) $schedule->pho_fin > $at
                && (string) $schedule->pho_estado !== 'FINALIZADO')
            ->sortByDesc('pho_inicio')
            ->first();
        $endedSuspensions = $schedules
            ->where('pho_tipo', 'SUSPENDER')
            ->filter(fn ($schedule) => $schedule->pho_fin !== null
                && (string) $schedule->pho_fin <= $at
                && (string) $schedule->pho_estado !== 'FINALIZADO');

        // The NORMAL end always wins over activation or suspension reactivation.
        if ($normalEnded) {
            $updates = [
                'promotion' => ['prm_estado' => 'FINALIZADA'],
                'schedules' => collect([$normal])->merge($endedSuspensions)
                    ->filter(fn ($schedule) => (string) $schedule->pho_estado !== 'FINALIZADO')
                    ->mapWithKeys(fn ($schedule) => [(int) $schedule->pho_id => ['pho_estado' => 'FINALIZADO']])
                    ->all(),
            ];

            return $this->transition($promotion, 'finalization', $updates, $write);
        }

        if ($currentSuspension && (string) $promotion->prm_estado === 'EN-PROCESO') {
            return $this->transition($promotion, 'suspension', [
                'promotion' => ['prm_estado' => 'SUSPENDIDO'],
                'schedules' => [
                    (int) $currentSuspension->pho_id => ['pho_estado' => 'ACTIVO'],
                ],
            ], $write);
        }

        if ((string) $promotion->prm_estado === 'SUSPENDIDO' && ! $currentSuspension) {
            $scheduleUpdates = $endedSuspensions
                ->mapWithKeys(fn ($schedule) => [(int) $schedule->pho_id => ['pho_estado' => 'FINALIZADO']])
                ->all();

            return $this->transition($promotion, 'reactivation', [
                'promotion' => ['prm_estado' => 'EN-PROCESO'],
                'schedules' => $scheduleUpdates,
            ], $write);
        }

        if (
            (string) $promotion->prm_estado === 'PENDIENTE'
            && $normalStarted
            && ! $currentSuspension
        ) {
            return $this->transition($promotion, 'activation', [
                'promotion' => ['prm_estado' => 'EN-PROCESO'],
                'schedules' => [
                    (int) $normal->pho_id => ['pho_estado' => 'ACTIVO'],
                ],
            ], $write);
        }

        if ($endedSuspensions->isNotEmpty()) {
            $updates = $endedSuspensions
                ->mapWithKeys(fn ($schedule) => [(int) $schedule->pho_id => ['pho_estado' => 'FINALIZADO']])
                ->all();

            if ($write) {
                $this->updateSchedules($updates);
            }

            return [
                'candidate' => null,
                'transition' => $updates === [] ? null : [
                    'promotionId' => $promotionId,
                    'type' => 'suspension_cleanup',
                    'from' => (string) $promotion->prm_estado,
                    'to' => (string) $promotion->prm_estado,
                ],
                'skipped' => null,
            ];
        }

        return $this->skipped($promotionId, 'Sin transicion aplicable para el estado y horario actuales.');
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function transition(object $promotion, string $type, array $updates, bool $write): array
    {
        $from = (string) $promotion->prm_estado;
        $to = (string) ($updates['promotion']['prm_estado'] ?? $from);

        if ($from === $to && ($updates['schedules'] ?? []) === []) {
            return $this->skipped((int) $promotion->prm_id, 'La transicion ya fue aplicada.');
        }

        if ($write) {
            if ($from !== $to) {
                DB::table('stj_promociones')
                    ->where('prm_id', $promotion->prm_id)
                    ->update($updates['promotion']);
            }
            $this->updateSchedules($updates['schedules'] ?? []);
        }

        return [
            'candidate' => $type,
            'transition' => [
                'promotionId' => (int) $promotion->prm_id,
                'type' => $type,
                'from' => $from,
                'to' => $to,
            ],
            'skipped' => null,
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $updates
     */
    private function updateSchedules(array $updates): void
    {
        foreach ($updates as $scheduleId => $values) {
            DB::table('stj_promociones_horario')
                ->where('pho_id', $scheduleId)
                ->update($values);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function skipped(int $promotionId, string $reason): array
    {
        return [
            'candidate' => null,
            'transition' => null,
            'skipped' => [
                'promotionId' => $promotionId,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(Carbon $reference, bool $dryRun): array
    {
        return [
            'referenceTime' => $reference->format('Y-m-d H:i:s'),
            'timezone' => $this->timezone(),
            'dryRun' => $dryRun,
            'candidates' => [
                'activation' => [],
                'finalization' => [],
                'suspension' => [],
                'reactivation' => [],
            ],
            'transitions' => [],
            'skipped' => [],
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $result
     */
    private function mergeResult(array &$summary, array $result): void
    {
        if (isset($result['candidate']) && isset($summary['candidates'][$result['candidate']])) {
            $summary['candidates'][$result['candidate']][] = $result['transition']['promotionId'];
        }
        if ($result['transition'] !== null) {
            $summary['transitions'][] = $result['transition'];
        }
        if ($result['skipped'] !== null) {
            $summary['skipped'][] = $result['skipped'];
        }
    }

    private function timezone(): string
    {
        return (string) config('promotions.timezone', 'America/El_Salvador');
    }
}
