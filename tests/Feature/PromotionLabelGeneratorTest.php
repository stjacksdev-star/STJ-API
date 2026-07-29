<?php

namespace Tests\Feature;

use App\Services\PromotionLabelGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionLabelGeneratorTest extends TestCase
{
    #[DataProvider('benefits')]
    public function test_it_generates_benefit_labels(array $promotion, string $expected): void
    {
        $labels = app(PromotionLabelGenerator::class)->generate($promotion + [
            'name' => 'Promoción',
            'commercialName' => '',
            'checkoutType' => 'TODO',
            'storeScope' => null,
        ], ['currencySymbol' => '$']);

        $this->assertSame($expected, $labels['benefitLabel']);
        $this->assertSame($expected, $labels['displayLabel']);
    }

    public static function benefits(): array
    {
        return [
            'percentage' => [[
                'promotionType' => 'DESCUENTO-SKU',
                'restriction' => null,
                'percentage' => 50,
                'price' => null,
            ], '50% de descuento'],
            'point price' => [[
                'promotionType' => 'PUNTO-PRECIO',
                'restriction' => null,
                'percentage' => null,
                'price' => 5,
            ], 'Llévatelo por $5'],
            'two for one' => [[
                'promotionType' => 'CONDICION-SKU',
                'restriction' => '2x1',
                'percentage' => null,
                'price' => null,
            ], 'Aplica 2x1'],
            'half price' => [[
                'promotionType' => 'CONDICION-SKU',
                'restriction' => '21/2',
                'percentage' => null,
                'price' => null,
            ], 'Segundo producto a mitad de precio'],
            'second fixed' => [[
                'promotionType' => 'CONDICION-SKU',
                'restriction' => '2doPrecio',
                'percentage' => null,
                'price' => 3,
            ], 'Segundo producto a $3'],
            'pair fixed' => [[
                'promotionType' => 'CONDICION-SKU',
                'restriction' => '2xPP',
                'percentage' => null,
                'price' => 15,
            ], '2 por $15'],
        ];
    }

    public function test_it_generates_contextual_scope_labels(): void
    {
        $generator = app(PromotionLabelGenerator::class);
        $base = [
            'name' => 'Promoción',
            'commercialName' => '',
            'promotionType' => 'CONDICION-SKU',
            'restriction' => '2x1',
            'percentage' => null,
            'price' => null,
        ];

        $this->assertSame('Oferta online', $generator->generate(
            $base + ['checkoutType' => 'D', 'storeScope' => null],
            [],
        )['scopeLabel']);
        $this->assertSame('Disponible en tiendas', $generator->generate(
            $base + ['checkoutType' => 'T', 'storeScope' => 'TODAS'],
            [],
        )['scopeLabel']);
        $this->assertSame('Oferta exclusiva en Multiplaza', $generator->generate(
            $base + ['checkoutType' => 'T', 'storeScope' => 'SELECCIONADAS'],
            ['storeName' => 'Multiplaza'],
        )['scopeLabel']);
    }
}
