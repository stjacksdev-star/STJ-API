<?php

namespace App\Console\Commands;

use App\Services\StorefrontNavigationService;
use Illuminate\Console\Command;

class BuildStorefrontNavigation extends Command
{
    protected $signature = 'storefront:navigation-build {country? : Código ISO del país}';

    protected $description = 'Genera snapshots cacheados del menú de navegación del storefront';

    public function handle(StorefrontNavigationService $navigation): int
    {
        $requested = strtolower(trim((string) $this->argument('country')));
        $countries = $requested !== '' ? [$requested] : $navigation->supportedCountryCodes();
        $failed = false;

        foreach ($countries as $country) {
            try {
                $payload = $navigation->build($country);

                if (! $payload) {
                    $this->error("{$country}: país no soportado");
                    $failed = true;

                    continue;
                }

                $this->info(strtoupper($country).': '.count($payload['groups']).' grupos generados');
            } catch (\Throwable $error) {
                report($error);
                $this->error(strtoupper($country).': '.$error->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
