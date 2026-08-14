<?php

namespace Tests\Unit;

use App\Support\CouponProductScope;
use PHPUnit\Framework\TestCase;

class CouponProductScopeTest extends TestCase
{
    public function test_boys_category_uses_the_catalog_group_landing(): void
    {
        $details = CouponProductScope::details((object) [
            'productScope' => 'GEN',
            'categoryName' => 'Niños',
            'promotionRule' => 'REGULAR',
        ], 'SV', 'http://localhost/stj-ecommerce/public/sv');

        $this->assertSame('Aplica a productos de la categoría Niños.', $details['label']);
        $this->assertSame('http://localhost/stj-ecommerce/public/sv/catalogo?group=boys', $details['url']);
    }

    public function test_excel_scope_uses_coupon_landing_without_duplicating_country(): void
    {
        $details = CouponProductScope::details((object) [
            'productScope' => 'PLA',
            'headerId' => 27167,
            'che_nombre_comercial' => 'Productos plantilla Excel',
        ], 'SV', 'http://localhost/stj-ecommerce/public/sv');

        $this->assertSame('http://localhost/stj-ecommerce/public/sv/cupones/27167/productos-plantilla-excel', $details['url']);
        $this->assertStringNotContainsString('/sv/sv/', $details['url']);
    }
}
