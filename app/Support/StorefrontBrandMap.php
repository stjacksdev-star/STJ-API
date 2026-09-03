<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class StorefrontBrandMap
{
    /**
     * @return array<string, array{canonical: string, aliases: array<int, string|null>}>
     */
    public static function definitions(): array
    {
        return [
            'jackco' => [
                'canonical' => 'JACK & CO',
                'aliases' => ['JACK & CO'],
            ],
            'stjacks' => [
                'canonical' => 'ST JACKS',
                'aliases' => ['ST JACKS', 'NUNGEE', null, ''],
            ],
            'basikos' => [
                'canonical' => 'BASIKOS',
                'aliases' => ['BASICS', 'BASIKOS', 'BASIKO'],
            ],
        ];
    }

    /**
     * @return array<int, string|null>
     */
    public static function aliases(string $slug): array
    {
        return self::definitions()[strtolower(trim($slug))]['aliases'] ?? [];
    }

    public static function canonical(string $slug): string
    {
        return self::definitions()[strtolower(trim($slug))]['canonical'] ?? strtoupper(trim($slug));
    }

    public static function applyProductBrandFilter($query, string $slug, string $column = 'p.pro_marca'): void
    {
        $aliases = self::aliases($slug);

        if ($aliases === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $normalizedAliases = collect($aliases)
            ->filter(fn ($alias) => $alias !== null && trim((string) $alias) !== '')
            ->map(fn ($alias) => strtoupper(trim((string) $alias)))
            ->unique()
            ->values()
            ->all();

        $includesBlank = collect($aliases)
            ->contains(fn ($alias) => $alias === null || trim((string) $alias) === '');

        $query->where(function ($subQuery) use ($column, $normalizedAliases, $includesBlank) {
            if ($normalizedAliases !== []) {
                $subQuery->whereIn(DB::raw("UPPER(TRIM({$column}))"), $normalizedAliases);
            }

            if ($includesBlank) {
                $method = $normalizedAliases === [] ? 'where' : 'orWhere';

                $subQuery->{$method}(function ($blankQuery) use ($column) {
                    $blankQuery
                        ->whereNull($column)
                        ->orWhereRaw("TRIM(COALESCE({$column}, '')) = ''");
                });
            }
        });
    }
}
