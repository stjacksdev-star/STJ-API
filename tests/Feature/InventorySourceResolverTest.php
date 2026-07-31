<?php

namespace Tests\Feature;

use App\Services\Inventory\InventorySourceResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    public function test_store_change_uses_its_explicit_rule(): void
    {
        $this->createRulesTable();
        DB::table('stj_inventory_source_rules')->insert([
            'isr_country_code' => 'SV',
            'isr_scope' => 'cart_store_change',
            'isr_source' => 'local_inventory',
            'isr_fallback_source' => null,
            'isr_is_active' => 1,
        ]);

        $rule = (new InventorySourceResolver)->resolveStoreChange('sv');

        $this->assertSame('cart_store_change', $rule['scope']);
        $this->assertSame('local_inventory', $rule['source']);
        $this->assertTrue($rule['from_rule']);
    }

    public function test_store_change_logs_missing_rule_and_only_uses_global_fallback(): void
    {
        $this->createRulesTable();
        config(['inventory.global_fallback_source' => 'external_api']);
        Log::spy();

        $rule = (new InventorySourceResolver)->resolveStoreChange('sv');

        $this->assertSame('external_api', $rule['source']);
        $this->assertFalse($rule['from_rule']);
        Log::shouldHaveReceived('error')->once();
    }

    private function createRulesTable(): void
    {
        Schema::create('stj_inventory_source_rules', function (Blueprint $table) {
            $table->id();
            $table->string('isr_country_code', 3);
            $table->string('isr_scope');
            $table->string('isr_source');
            $table->string('isr_fallback_source')->nullable();
            $table->boolean('isr_is_active');
        });
    }
}
