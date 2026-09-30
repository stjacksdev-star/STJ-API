<?php

namespace Tests\Feature;

use App\Services\Dashboard\PromotionAssetService;
use App\Services\Dashboard\PromotionHistoryService;
use App\Services\Media\ImageOptimizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class PromotionAssetTypeUniquenessTest extends TestCase
{
    private PromotionAssetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stj_assets', function (Blueprint $table): void {
            $table->id('ast_id');
            $table->unsignedBigInteger('ast_idpromocion');
            $table->unsignedInteger('ast_tipo_accion');
            $table->string('ast_tipo');
        });

        DB::table('stj_assets')->insert([
            ['ast_idpromocion' => 2106, 'ast_tipo_accion' => 1, 'ast_tipo' => 'SLIDER'],
            ['ast_idpromocion' => 2106, 'ast_tipo_accion' => 1, 'ast_tipo' => 'BANNER'],
        ]);

        $this->service = new PromotionAssetService(
            $this->createMock(ImageOptimizer::class),
            $this->createMock(PromotionHistoryService::class),
        );
    }

    public function test_promotion_can_have_multiple_slider_assets(): void
    {
        $this->validateUniqueType(2106, 'SLIDER');

        $this->addToAssertionCount(1);
    }

    public function test_other_asset_types_remain_unique_per_promotion(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('La promocion ya tiene un asset de tipo BANNER.');

        $this->validateUniqueType(2106, 'BANNER');
    }

    private function validateUniqueType(int $promotionId, string $type): void
    {
        $method = new ReflectionMethod($this->service, 'ensureUniqueType');
        $method->invoke($this->service, $promotionId, $type);
    }
}
