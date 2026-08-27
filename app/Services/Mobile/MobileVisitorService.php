<?php

namespace App\Services\Mobile;

use App\Models\StorefrontVisitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MobileVisitorService
{
    public function resolve(string $installationUuid, int $countryId, string $platform): StorefrontVisitor
    {
        $uuid = strtolower($installationUuid);
        $origin = 'APP-'.strtoupper($platform);
        $now = now();
        $expiresAt = $now->copy()->addYear();

        try {
            $visitor = StorefrontVisitor::query()->firstOrCreate(
                ['vis_uuid' => $uuid],
                [
                    'vis_origen' => $origin,
                    'vis_pais_id' => $countryId,
                    'vis_primera_visita' => $now,
                    'vis_ultima_visita' => $now,
                    'vis_expira_en' => $expiresAt,
                    'vis_creado_en' => $now,
                    'vis_actualizado_en' => $now,
                ],
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $visitor = StorefrontVisitor::query()->where('vis_uuid', $uuid)->firstOrFail();
        }

        if (! $visitor->wasRecentlyCreated) {
            $visitor->forceFill([
                'vis_origen' => $origin,
                'vis_pais_id' => $countryId,
                'vis_ultima_visita' => $now,
                'vis_expira_en' => $expiresAt,
                'vis_actualizado_en' => $now,
            ])->save();
        }

        return $visitor;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[1] ?? ''), ['1062', '19'], true);
    }
}
