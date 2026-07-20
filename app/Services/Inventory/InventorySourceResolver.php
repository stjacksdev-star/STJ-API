<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventorySourceResolver
{
    public function resolveRequired(string $countryCode, string $scope): array
    {
        $countryCode = strtolower(trim($countryCode));
        $scope = trim($scope);
        if (! Schema::hasTable('stj_inventory_source_rules')) {
            throw ValidationException::withMessages(['inventory_rule' => 'No existe la tabla de reglas de inventario.']);
        }

        $rule = DB::table('stj_inventory_source_rules')
            ->where('isr_country_code', strtoupper($countryCode))
            ->where('isr_scope', $scope)
            ->where('isr_is_active', 1)
            ->first();

        if (! $rule || ! in_array((string) $rule->isr_source, config('inventory.sources', []), true)) {
            throw ValidationException::withMessages(['inventory_rule' => "No existe una regla activa y valida para {$countryCode}/{$scope}."]);
        }

        return ['country' => $countryCode, 'scope' => $scope, 'source' => (string) $rule->isr_source, 'fallback_source' => $rule->isr_fallback_source ?: null, 'from_rule' => true];
    }

    public function resolve(string $countryCode, string $scope): array
    {
        $countryCode = strtolower(trim($countryCode));
        $scope = trim($scope);
        $cacheSeconds = max(1, (int) config('inventory.rule_cache_seconds', 300));

        return Cache::remember(
            "inventory_source_rule:{$countryCode}:{$scope}",
            now()->addSeconds($cacheSeconds),
            function () use ($countryCode, $scope) {
                $rule = Schema::hasTable('stj_inventory_source_rules')
                    ? DB::table('stj_inventory_source_rules')
                        ->where('isr_country_code', strtoupper($countryCode))
                        ->where('isr_scope', $scope)
                        ->where('isr_is_active', 1)
                        ->first()
                    : null;

                if ($rule) {
                    return [
                        'country' => $countryCode,
                        'scope' => $scope,
                        'source' => (string) $rule->isr_source,
                        'fallback_source' => $rule->isr_fallback_source ?: null,
                        'from_rule' => true,
                    ];
                }

                $defaults = config("inventory.defaults.{$scope}", []);

                return [
                    'country' => $countryCode,
                    'scope' => $scope,
                    'source' => $defaults['source'] ?? 'local_inventory',
                    'fallback_source' => $defaults['fallback_source'] ?? null,
                    'from_rule' => false,
                ];
            }
        );
    }
}
