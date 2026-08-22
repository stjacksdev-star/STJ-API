<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileSizeGuideService
{
    private const MEASUREMENTS = [
        'peso' => 'PESO',
        'longitud' => 'LONGITUD',
        'pecho' => 'PECHO',
        'cintura' => 'CINTURA',
        'altura' => 'ALTURA',
        'cadera' => 'CADERA',
    ];

    public function html(int $countryId, int $categoryId): string
    {
        if (! DB::table('stj_paises')->where('pai_id', $countryId)->exists()) {
            throw ValidationException::withMessages(['countryId' => 'Pais no soportado.']);
        }
        if (! DB::table('stj_categorias')->where('cat_id', $categoryId)->exists()) {
            throw ValidationException::withMessages(['category' => 'Categoria no encontrada.']);
        }

        $guides = DB::table('stj_guia_tallas')
            ->where('gta_categoria', $categoryId)
            ->orderBy('gta_orden')
            ->get();
        $columns = collect(self::MEASUREMENTS)
            ->filter(fn (string $label, string $field) => $guides->contains(
                fn (object $guide) => mb_strlen(trim((string) ($guide->{'gta_'.$field} ?? ''))) > 1
            ));

        return '<div style="text-align: center"><h1>Guía de tallas</h1></div>'
            .$this->table('Pulgadas', $guides, $columns->all(), false)
            .$this->table('Centimetros', $guides, $columns->all(), true)
            .'<br/><p style="text-align:center;">Peso: en libras.</p>';
    }

    private function table(string $title, $guides, array $columns, bool $centimeters): string
    {
        $html = '<ion-card><ion-card-header><ion-card-title>'.$title.'</ion-card-title></ion-card-header>'
            .'<ion-card-content><table style="width: 100%;"><thead><tr style="border-bottom: 1px solid black;">'
            .'<td style="text-align:center;">TALLA</td>';
        foreach ($columns as $label) {
            $html .= '<td>'.$label.'</td>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($guides as $guide) {
            $html .= '<tr><td style="text-align:center;">'.$this->escape($guide->gta_talla ?? '').'</td>';
            foreach (array_keys($columns) as $field) {
                $column = 'gta_'.$field.($centimeters && $field !== 'peso' ? '_cm' : '');
                $html .= '<td>'.$this->escape($guide->{$column} ?? '').'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></ion-card-content></ion-card>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
