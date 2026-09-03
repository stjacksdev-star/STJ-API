<?php

namespace Tests\Unit;

use App\Support\StorefrontBrandMap;
use PHPUnit\Framework\TestCase;

class StorefrontBrandMapTest extends TestCase
{
    public function test_basikos_keeps_all_database_aliases(): void
    {
        $this->assertSame(
            ['BASICS', 'BASIKOS', 'BASIKO'],
            StorefrontBrandMap::aliases('basikos')
        );
        $this->assertSame('BASIKOS', StorefrontBrandMap::canonical('basikos'));
    }
}
