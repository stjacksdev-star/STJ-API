<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StorefrontProductSearch
{
    private const IGNORED_WORDS = [
        'a', 'al', 'de', 'del', 'el', 'en', 'la', 'las', 'lo', 'los',
        'o', 'para', 'por', 'un', 'una', 'unas', 'unos', 'y', 'con', 'sin',
        'moda', 'prenda', 'prendas', 'producto', 'productos', 'ropa',
    ];

    /**
     * Customer language mapped to the commercial category names stored in STJ.
     * Every inner array is an OR group; separate groups are combined with AND.
     */
    private const INTENT_ALIASES = [
        'hombre' => ['Caballeros'],
        'hombres' => ['Caballeros'],
        'caballero' => ['Caballeros'],
        'caballeros' => ['Caballeros'],
        'adulto' => ['Caballeros'],
        'adultos' => ['Caballeros'],
        'grande' => ['Caballeros'],
        'grandes' => ['Caballeros'],
        'masculino' => ['Caballeros'],
        'mujer' => ['Damas'],
        'mujeres' => ['Damas'],
        'dama' => ['Damas'],
        'damas' => ['Damas'],
        'adulta' => ['Damas'],
        'adultas' => ['Damas'],
        'femenino' => ['Damas'],
        'nino' => ['Niños', 'Ninos'],
        'ninos' => ['Niños', 'Ninos'],
        'nina' => ['Niñas', 'Ninas'],
        'ninas' => ['Niñas', 'Ninas'],
        'chico' => ['Teen Chicos'],
        'chicos' => ['Teen Chicos'],
        'chica' => ['Teen Chicas'],
        'chicas' => ['Teen Chicas'],
        'teen' => ['Teen Chicos', 'Teen Chicas'],
        'teens' => ['Teen Chicos', 'Teen Chicas'],
        'adolescente' => ['Teen Chicos', 'Teen Chicas'],
        'adolescentes' => ['Teen Chicos', 'Teen Chicas'],
        'joven' => ['Teen Chicos', 'Teen Chicas'],
        'jovenes' => ['Teen Chicos', 'Teen Chicas'],
        'juvenil' => ['Teen Chicos', 'Teen Chicas'],
        'toddler' => ['Toddler Niñas', 'Toddler Niños', 'Toddler Ninas', 'Toddler Ninos'],
        'toddlers' => ['Toddler Niñas', 'Toddler Niños', 'Toddler Ninas', 'Toddler Ninos'],
        'bebe' => ['Bebas', 'Bebos', 'Bebes Unisex'],
        'bebes' => ['Bebas', 'Bebos', 'Bebes Unisex'],
    ];

    private const PRODUCT_COLUMNS = [
        'pro_nombre',
        'pro_codigo',
        'pro_descripcion',
        'pro_personaje',
        'pro_marca',
        'pro_oc_marca',
        'pro_tags',
        'pro_oc_categoria',
        'pro_oc_genero',
        'pro_oc_coleccion',
        'pro_genero',
        'pro_licencia',
        'pro_license',
        'pro_oc_licencia',
    ];

    public static function apply(Builder $query, string $text, string $productAlias = 'p', string $categoryAlias = 'c', string $subcategoryAlias = 'sc'): void
    {
        $termGroups = self::termGroups($text);

        if ($termGroups === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $productColumns = self::productColumns();

        foreach ($termGroups as $alternatives) {
            $query->where(function (Builder $matches) use ($alternatives, $productColumns, $productAlias, $categoryAlias, $subcategoryAlias) {
                foreach ($alternatives as $alternativeIndex => $term) {
                    $pattern = '%'.self::escapeLike(Str::lower($term)).'%';

                    foreach ($productColumns as $columnIndex => $column) {
                        $method = $alternativeIndex === 0 && $columnIndex === 0 ? 'whereRaw' : 'orWhereRaw';
                        $matches->{$method}("LOWER({$productAlias}.{$column}) LIKE ? ESCAPE '!'", [$pattern]);
                    }

                    $matches->orWhereRaw("LOWER({$categoryAlias}.cat_nombre) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER({$subcategoryAlias}.sca_nombre) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
    }

    public static function terms(string $text): array
    {
        return collect(preg_split('/\s+/u', Str::lower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $term) => trim($term, " \t\n\r\0\x0B.,;:¡!¿?()[]{}\"'"))
            ->filter(fn (string $term) => $term !== '' && ! in_array(Str::ascii($term), self::IGNORED_WORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    public static function termGroups(string $text): array
    {
        return collect(self::terms($text))
            ->map(function (string $term) {
                $lookup = Str::lower(Str::ascii($term));

                return self::INTENT_ALIASES[$lookup] ?? [$term];
            })
            ->unique(fn (array $alternatives) => implode('|', $alternatives))
            ->values()
            ->all();
    }

    private static function productColumns(): array
    {
        $available = Schema::getColumnListing('stj_productos');

        return array_values(array_intersect(self::PRODUCT_COLUMNS, $available));
    }

    private static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
