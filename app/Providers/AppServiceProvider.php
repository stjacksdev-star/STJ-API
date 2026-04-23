<?php

namespace App\Providers;

use App\Models\User;
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
