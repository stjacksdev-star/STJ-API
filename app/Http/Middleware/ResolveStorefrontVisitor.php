<?php

namespace App\Http\Middleware;

use App\Models\StorefrontVisitor;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontVisitor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/storefront/*')
            || $request->is('api/storefront/payments/powertranz/return/*')
            || $request->is('api/storefront/push/deliveries/*/click')) {
            return $next($request);
        }

        $cookieName = (string) config('visitor.cookie', 'stj_visitor');
        $candidate = (string) $request->cookie($cookieName, '');
        $uuid = Str::isUuid($candidate) ? strtolower($candidate) : (string) Str::uuid();
        $now = now();
        $expiresAt = $now->copy()->addDays(max(1, (int) config('visitor.ttl_days', 365)));
        $countryId = $this->countryId($request);

        try {
            $visitor = StorefrontVisitor::query()->firstOrCreate(
                ['vis_uuid' => $uuid],
                [
                    'vis_origen' => (string) config('visitor.origin', 'WEB'),
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
                'vis_pais_id' => $countryId ?? $visitor->vis_pais_id,
                'vis_ultima_visita' => $now,
                'vis_expira_en' => $expiresAt,
                'vis_actualizado_en' => $now,
            ])->save();
        }

        $request->attributes->set('storefrontVisitor', $visitor);

        $response = $next($request);
        if ($request->attributes->get('forgetStorefrontVisitor') === true) {
            $response->headers->setCookie(cookie()->forget($cookieName, '/', config('visitor.domain')));

            return $response;
        }

        $response->headers->setCookie(cookie(
            $cookieName,
            $uuid,
            max(1, (int) config('visitor.ttl_days', 365)) * 1440,
            '/',
            config('visitor.domain'),
            (bool) config('visitor.secure'),
            true,
            false,
            'lax',
        ));

        return $response;
    }

    private function countryId(Request $request): ?int
    {
        $country = trim((string) $request->route('country', ''));

        if ($country === '') {
            return null;
        }

        $id = DB::table('stj_paises')->where('pai_codigo', strtoupper($country))->value('pai_id');

        return $id === null ? null : (int) $id;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[1] ?? ''), ['1062', '19'], true);
    }
}
