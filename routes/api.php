<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\StorefrontHomeController;
use App\Http\Controllers\Api\StorefrontCatalogController;
use App\Http\Controllers\Api\StorefrontProductController;
use App\Http\Controllers\Api\StorefrontProductAvailabilityController;
use App\Http\Controllers\Api\StorefrontCheckoutValidationController;
use App\Http\Controllers\Api\StorefrontOrderController;
use App\Http\Controllers\Api\Dashboard\CollectionAssetController as DashboardCollectionAssetController;
use App\Http\Controllers\Api\Dashboard\CollectionController as DashboardCollectionController;
use App\Http\Controllers\Api\Dashboard\OrderReferenceController as DashboardOrderReferenceController;
use App\Http\Controllers\Api\Dashboard\PromotionAssetController as DashboardPromotionAssetController;
use App\Http\Controllers\Api\Dashboard\PromotionController as DashboardPromotionController;
use App\Http\Controllers\Api\Dashboard\SalesKpiController as DashboardSalesKpiController;


Route::post('/login', [AuthController::class, 'login']);
Route::get('/storefront/home/{country}', [StorefrontHomeController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/catalog/{country}', [StorefrontCatalogController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/product/{country}/{slug}', [StorefrontProductController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/product/{country}/{slug}/availability', [StorefrontProductAvailabilityController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::post('/storefront/checkout/validate', StorefrontCheckoutValidationController::class);
Route::post('/storefront/orders', [StorefrontOrderController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/productos', [ProductoController::class, 'listar']);
    Route::get('/pedido/detalle', [PedidoController::class, 'getPedidoById']);

    Route::prefix('dashboard')->group(function () {
        Route::get('/collections', [DashboardCollectionController::class, 'index']);
        Route::post('/collections', [DashboardCollectionController::class, 'store']);
        Route::post('/collections/{collection}', [DashboardCollectionController::class, 'update']);
        Route::get('/collections/{collection}/assets', [DashboardCollectionAssetController::class, 'index']);
        Route::post('/collections/{collection}/assets', [DashboardCollectionAssetController::class, 'store']);
        Route::post('/assets/{asset}', [DashboardCollectionAssetController::class, 'update']);
        Route::get('/promotions', [DashboardPromotionController::class, 'index']);
        Route::post('/promotions', [DashboardPromotionController::class, 'store']);
        Route::post('/promotions/{promotion}/schedule', [DashboardPromotionController::class, 'updateSchedule']);
        Route::get('/promotions/{promotion}/assets', [DashboardPromotionAssetController::class, 'index']);
        Route::post('/promotions/{promotion}/assets', [DashboardPromotionAssetController::class, 'store']);
        Route::post('/promotions/assets/{asset}', [DashboardPromotionAssetController::class, 'update']);
        Route::delete('/promotions/assets/{asset}', [DashboardPromotionAssetController::class, 'destroy']);
        Route::post('/promotions/{promotion}/header', [DashboardPromotionAssetController::class, 'updateHeader']);
        Route::get('/sales/kpi', [DashboardSalesKpiController::class, 'show']);
        Route::get('/sales/orders', [DashboardSalesKpiController::class, 'orders']);
        Route::get('/orders/reference', [DashboardOrderReferenceController::class, 'show']);
        Route::get('/orders/product', [DashboardOrderReferenceController::class, 'product']);
        Route::post('/orders/lines/{line}', [DashboardOrderReferenceController::class, 'updateLine']);
        Route::post('/orders/process', [DashboardOrderReferenceController::class, 'process']);
    });
});

Route::get('/debug-db', function () {
    return [
        'app_env' => app()->environment(),
        'db_default' => config('database.default'),
        'db_database' => config('database.connections.mysql.database'),
        'sqlite_database' => config('database.connections.sqlite.database'),
        'env_db_connection' => env('DB_CONNECTION'),
        'env_db_database' => env('DB_DATABASE'),
    ];
});
