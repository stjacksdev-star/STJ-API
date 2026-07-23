<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class PromotionHistoryService
{
    /**
     * @param  array<string, mixed>  $actor
     */
    public function record(int $promotionId, string $action, string $description, array $actor = []): void
    {
        DB::table('stj_promociones_historial')->insert([
            'pph_promocion' => $promotionId,
            'pph_usuario_id' => $this->stringOrNull(
                $actor['id'] ?? $actor['username'] ?? $actor['email'] ?? null,
                80,
            ),
            'pph_usuario_nombre' => $this->stringOrNull(
                $actor['name'] ?? $actor['username'] ?? $actor['email'] ?? null,
                150,
            ),
            'pph_accion' => $action,
            'pph_descripcion' => mb_substr(trim($description), 0, 255),
            'pph_fecha' => now(),
        ]);
    }

    private function stringOrNull(mixed $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $length) : null;
    }
}
