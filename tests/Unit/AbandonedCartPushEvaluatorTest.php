<?php

namespace Tests\Unit;

use App\Services\AbandonedCartPushEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AbandonedCartPushEvaluatorTest extends TestCase
{
    private AbandonedCartPushEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AbandonedCartPushEvaluator;
    }

    #[Test]
    public function it_builds_an_idempotency_key_per_cart_version_stage_and_subscription(): void
    {
        $this->assertSame('ABANDONED_CART:25:7:PRIMARY:9', $this->evaluator->idempotencyKey(25, 7, 9));
        $this->assertNotSame($this->evaluator->idempotencyKey(25, 7, 9), $this->evaluator->idempotencyKey(25, 8, 9));
        $this->assertNotSame($this->evaluator->idempotencyKey(25, 7, 9), $this->evaluator->idempotencyKey(25, 7, 10));
    }

    #[Test]
    public function it_renders_only_known_template_values(): void
    {
        $rendered = $this->evaluator->render('/{country}/carrito?cart={cart_uuid}&unknown={unknown}', [
            'country' => 'sv',
            'cart_uuid' => 'cart-uuid',
        ]);

        $this->assertSame('/sv/carrito?cart=cart-uuid&unknown={unknown}', $rendered);
    }

    #[Test]
    public function it_accepts_an_unrestricted_country_list_or_matching_ids_and_codes(): void
    {
        $this->assertTrue($this->evaluator->countryIsEnabled(null, 1, 'SV'));
        $this->assertTrue($this->evaluator->countryIsEnabled([], 1, 'SV'));
        $this->assertTrue($this->evaluator->countryIsEnabled(['sv'], 1, 'SV'));
        $this->assertTrue($this->evaluator->countryIsEnabled([1], 1, 'SV'));
        $this->assertFalse($this->evaluator->countryIsEnabled(['GT'], 1, 'SV'));
    }
}
