<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('powertranz-start', function (Request $request) {
            $visitor = (string) ($request->cookie('stj_visitor') ?: $request->ip());
            $order = (string) $request->route('order', 'unknown');

            return Limit::perMinute(15)
                ->by("{$visitor}:{$order}")
                ->response(fn (Request $request, array $headers) => response()->json([
                    'ok' => false,
                    'message' => 'Has realizado varios intentos de pago seguidos. Espera unos segundos e inténtalo nuevamente.',
                ], 429, $headers));
        });

        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid) {
            if ($isValid) {
                return true;
            }

            if (! $accessToken?->can('dashboard')) {
                return false;
            }

            return $accessToken->tokenable instanceof User
                && $accessToken->expires_at
                && $accessToken->expires_at->isFuture();
        });
    }
}
