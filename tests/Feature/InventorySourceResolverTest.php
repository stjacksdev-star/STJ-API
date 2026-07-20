<?php

namespace Tests\Feature;

use App\Services\Inventory\InventorySourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventorySourceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_requires_an_explicit_active_rule(): void
    {
        $this->expectException(ValidationException::class);
        (new InventorySourceResolver)->resolveRequired('hn', 'checkout');
    }
}
