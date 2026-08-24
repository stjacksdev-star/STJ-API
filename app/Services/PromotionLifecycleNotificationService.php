<?php

namespace App\Services;

use App\Services\Mail\Smtp2GoMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PromotionLifecycleNotificationService
{
    public function __construct(private readonly Smtp2GoMailer $mailer) {}

    /**
     * @param  array<int, array<string, mixed>>  $transitions
     * @return array{sent: int, skipped: int, errors: array<int, array<string, mixed>>}
     */
    public function send(array $transitions): array
    {
        $recipients = $this->recipients();
        $summary = ['sent' => 0, 'skipped' => 0, 'errors' => []];

        if ($recipients === []) {
            $summary['skipped'] = count($transitions);

            return $summary;
        }

        foreach ($transitions as $transition) {
            if (! in_array($transition['type'] ?? null, ['activation', 'finalization', 'suspension', 'reactivation'], true)) {
                $summary['skipped']++;
                continue;
            }

            $promotionId = (int) ($transition['promotionId'] ?? 0);

            try {
                $promotion = $this->promotion($promotionId);
                if (! $promotion) {
                    $summary['skipped']++;
                    continue;
                }

                $message = $this->message((string) $transition['type'], $promotion);
                $this->mailer->sendHtml(
                    $recipients,
                    $this->subject((string) $transition['type'], $promotion),
                    '<p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>'
                    .'<p>Proceso automático ejecutado: '.htmlspecialchars(now($this->timezone())->format('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8').'</p>',
                );
                $summary['sent']++;
            } catch (Throwable $exception) {
                $error = ['promotionId' => $promotionId, 'message' => $exception->getMessage()];
                $summary['errors'][] = $error;
                Log::error('No se pudo enviar la notificación del ciclo de promoción.', $error);
            }
        }

        return $summary;
    }

    private function promotion(int $promotionId): ?object
    {
        $promotion = DB::table('stj_promociones as p')
            ->leftJoin('stj_paises as c', 'c.pai_id', '=', 'p.prm_pais')
            ->where('p.prm_id', $promotionId)
            ->first(['p.prm_id', 'p.prm_nombre', 'p.prm_pais', 'c.pai_codigo', 'c.pai_nombre']);

        if (! $promotion) {
            return null;
        }

        $promotion->productCount = DB::table('stj_promociones_producto')
            ->where('ppr_promocion', $promotionId)
            ->distinct()
            ->count('ppr_producto');

        return $promotion;
    }

    private function message(string $type, object $promotion): string
    {
        $count = (int) $promotion->productCount;
        $noun = $count === 1 ? 'producto' : 'productos';
        $singular = $count === 1;
        $action = match ($type) {
            'activation' => $singular ? 'activado' : 'activados',
            'finalization' => $singular ? 'desactivado' : 'desactivados',
            'suspension' => $singular ? 'desactivado temporalmente' : 'desactivados temporalmente',
            'reactivation' => $singular ? 'reactivado' : 'reactivados',
        };

        return sprintf('%d %s %s de la promoción "%s" en %s.', $count, $noun, $action, (string) $promotion->prm_nombre, $this->country($promotion));
    }

    private function subject(string $type, object $promotion): string
    {
        $event = match ($type) {
            'activation' => 'activada',
            'finalization' => 'desactivada',
            'suspension' => 'suspendida',
            'reactivation' => 'reactivada',
        };

        return sprintf('[Promociones] %s %s - %s', (string) $promotion->prm_nombre, $event, $this->country($promotion));
    }

    private function country(object $promotion): string
    {
        return trim((string) ($promotion->pai_nombre ?: $promotion->pai_codigo ?: 'País #'.$promotion->prm_pais));
    }

    /** @return array<int, string> */
    private function recipients(): array
    {
        return collect(config('promotions.notification_recipients', []))
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function timezone(): string
    {
        return (string) config('promotions.timezone', 'America/El_Salvador');
    }
}
