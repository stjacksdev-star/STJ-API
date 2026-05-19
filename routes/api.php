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
use App\Http\Controllers\Api\Dashboard\AccountingReportController as DashboardAccountingReportController;
use App\Http\Controllers\Api\Dashboard\AppointmentController as DashboardAppointmentController;
use App\Http\Controllers\Api\Dashboard\ClaimController as DashboardClaimController;
use App\Http\Controllers\Api\Dashboard\CollectionAssetController as DashboardCollectionAssetController;
use App\Http\Controllers\Api\Dashboard\CollectionController as DashboardCollectionController;
use App\Http\Controllers\Api\Dashboard\OrderReferenceController as DashboardOrderReferenceController;
use App\Http\Controllers\Api\Dashboard\PromotionAssetController as DashboardPromotionAssetController;
use App\Http\Controllers\Api\Dashboard\PromotionController as DashboardPromotionController;
use App\Http\Controllers\Api\Dashboard\ProductCategoryController as DashboardProductCategoryController;
use App\Http\Controllers\Api\Dashboard\ProductCountryController as DashboardProductCountryController;
use App\Http\Controllers\Api\Dashboard\ProductMasterController as DashboardProductMasterController;
use App\Http\Controllers\Api\Dashboard\SalesKpiController as DashboardSalesKpiController;
use App\Http\Controllers\Api\Dashboard\StoreReportController as DashboardStoreReportController;
use App\Http\Controllers\Api\Dashboard\SubscriberController as DashboardSubscriberController;


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
        Route::get('/product-categories', [DashboardProductCategoryController::class, 'index']);
        Route::post('/product-categories', [DashboardProductCategoryController::class, 'store']);
        Route::post('/product-categories/{category}', [DashboardProductCategoryController::class, 'update']);
        Route::delete('/product-categories/{category}', [DashboardProductCategoryController::class, 'destroy']);
        Route::get('/products/master', [DashboardProductMasterController::class, 'index']);
        Route::post('/products/master/import', [DashboardProductMasterController::class, 'import']);
        Route::post('/products/master/photos/import', [DashboardProductMasterController::class, 'importPhotos']);
        Route::get('/products/master/{product}', [DashboardProductMasterController::class, 'show']);
        Route::get('/products/master/{product}/photos', [DashboardProductMasterController::class, 'photos']);
        Route::get('/products/master/{product}/countries', [DashboardProductMasterController::class, 'countries']);
        Route::get('/products/country/countries', [DashboardProductCountryController::class, 'countries']);
        Route::post('/products/country/import', [DashboardProductCountryController::class, 'import']);
        Route::post('/products/country/deactivate', [DashboardProductCountryController::class, 'deactivate']);
        Route::get('/sales/kpi', [DashboardSalesKpiController::class, 'show']);
        Route::get('/sales/regional-chart', [DashboardSalesKpiController::class, 'regionalSalesChart']);
        Route::get('/sales/conversion', [DashboardSalesKpiController::class, 'conversionChart']);
        Route::get('/sales/visits', [DashboardSalesKpiController::class, 'visitsChart']);
        Route::get('/sales/satisfaction', [DashboardSalesKpiController::class, 'satisfaction']);
        Route::get('/sales/categories', [DashboardSalesKpiController::class, 'categories']);
        Route::get('/sales/segments', [DashboardSalesKpiController::class, 'segments']);
        Route::get('/sales/payment-forms', [DashboardSalesKpiController::class, 'paymentForms']);
        Route::get('/sales/geographic', [DashboardSalesKpiController::class, 'geographic']);
        Route::get('/sales/app', [DashboardSalesKpiController::class, 'app']);
        Route::get('/sales/orders', [DashboardSalesKpiController::class, 'orders']);
        Route::get('/appointments/catalog', [DashboardAppointmentController::class, 'catalog']);
        Route::get('/appointments', [DashboardAppointmentController::class, 'index']);
        Route::get('/claims', [DashboardClaimController::class, 'index']);
        Route::post('/claims', [DashboardClaimController::class, 'store']);
        Route::post('/claims/{claim}', [DashboardClaimController::class, 'update']);
        Route::delete('/claims/{claim}', [DashboardClaimController::class, 'destroy']);
        Route::get('/subscribers', [DashboardSubscriberController::class, 'index']);
        Route::post('/subscribers', [DashboardSubscriberController::class, 'store']);
        Route::post('/subscribers/{subscriber}', [DashboardSubscriberController::class, 'update']);
        Route::delete('/subscribers/{subscriber}', [DashboardSubscriberController::class, 'destroy']);
        Route::get('/reports/store/catalog', [DashboardStoreReportController::class, 'catalog']);
        Route::get('/reports/store/virtual-cut', [DashboardStoreReportController::class, 'virtualCut']);
        Route::get('/reports/store/pending-items', [DashboardStoreReportController::class, 'pendingItems']);
        Route::get('/reports/store/pending-items-by-order', [DashboardStoreReportController::class, 'pendingItemsByOrder']);
        Route::get('/reports/accounting/3/count', [DashboardAccountingReportController::class, 'count3']);
        Route::get('/reports/accounting/3/export', [DashboardAccountingReportController::class, 'export3']);
        Route::get('/reports/accounting/sales-by-store', [DashboardAccountingReportController::class, 'salesByStore']);
        Route::get('/orders/reference', [DashboardOrderReferenceController::class, 'show']);
        Route::get('/orders/search', [DashboardOrderReferenceController::class, 'search']);
        Route::get('/orders/payment-attempts', [DashboardOrderReferenceController::class, 'paymentAttempts']);
        Route::get('/orders/refunds', [DashboardOrderReferenceController::class, 'refunds']);
        Route::get('/orders/product', [DashboardOrderReferenceController::class, 'product']);
        Route::post('/orders/lines/{line}', [DashboardOrderReferenceController::class, 'updateLine']);
        Route::post('/orders/process', [DashboardOrderReferenceController::class, 'process']);
        Route::post('/orders/route', [DashboardOrderReferenceController::class, 'markInRoute']);
        Route::post('/orders/deliver', [DashboardOrderReferenceController::class, 'deliver']);
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
