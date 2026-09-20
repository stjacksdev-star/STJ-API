<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Mobile\MobileCartController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileCartPresentationTest extends TestCase
{
    public function test_mobile_payload_keeps_authoritative_line_amounts_and_legacy_aliases(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('stj_productos', function (Blueprint $table) {
            $table->integer('pro_id');
            foreach (['pro_codigo', 'pro_nombre', 'pro_marca', 'pro_oc_marca', 'pro_thumbs', 'pro_categoria'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $base = ['id' => 1, 'productId' => 10, 'sku' => 'SKU10', 'name' => 'Producto', 'size' => 'M',
            'pendingPromotion' => ['id' => 12, 'type' => 'CONDICION-SKU', 'restriction' => '2xPP'],
            'selected' => true, 'status' => 'DISPONIBLE', 'unavailableReason' => null, 'imageUrl' => null];
        $items = [
            [...$base, 'quantity' => 2, 'regularPrice' => 525, 'finalPrice' => 475, 'baseSubtotal' => 1050, 'lineSubtotal' => 950, 'discountPercentage' => 9.523809],
            [...$base, 'id' => 2, 'quantity' => 1, 'regularPrice' => 25.95, 'finalPrice' => 12.97, 'baseSubtotal' => 25.95, 'lineSubtotal' => 12.97, 'discountPercentage' => 50],
            [...$base, 'id' => 3, 'quantity' => 1, 'regularPrice' => 13.95, 'finalPrice' => 2.79, 'baseSubtotal' => 13.95, 'lineSubtotal' => 2.79, 'discountPercentage' => 80],
        ];
        $controller = (new \ReflectionClass(MobileCartController::class))->newInstanceWithoutConstructor();
        $payload = (new \ReflectionMethod($controller, 'legacy'))->invoke($controller, ['cart' => ['items' => $items, 'type' => 'TIENDA', 'totals' => ['total' => 965.76]]], true);
        $this->assertSame('965.76', $payload['monto_tipo']);
        foreach ($items as $index => $item) {
            $row = $payload['productos'][$index];
            $this->assertSame((float) $item['lineSubtotal'], $row['lineSubtotal']);
            $this->assertSame((float) $item['baseSubtotal'], $row['baseSubtotal']);
            $this->assertSame((float) $item['discountPercentage'], $row['discountPercentage']);
            $this->assertSame($item['pendingPromotion'], $row['pendingPromotion']);
            $this->assertSame(round((1 - $item['finalPrice'] / $item['regularPrice']) * 100, 4), $row['car_descuento']);
        }
    }
}
