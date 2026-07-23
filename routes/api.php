<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Dashboard\AccountingReportController as DashboardAccountingReportController;
use App\Http\Controllers\Api\Dashboard\AppointmentController as DashboardAppointmentController;
use App\Http\Controllers\Api\Dashboard\AssetPublicationController as DashboardAssetPublicationController;
use App\Http\Controllers\Api\Dashboard\ClaimController as DashboardClaimController;
use App\Http\Controllers\Api\Dashboard\CollectionAssetController as DashboardCollectionAssetController;
use App\Http\Controllers\Api\Dashboard\CollectionController as DashboardCollectionController;
use App\Http\Controllers\Api\Dashboard\OrderReferenceController as DashboardOrderReferenceController;
use App\Http\Controllers\Api\Dashboard\ProductCategoryController as DashboardProductCategoryController;
use App\Http\Controllers\Api\Dashboard\ProductCountryController as DashboardProductCountryController;
use App\Http\Controllers\Api\Dashboard\ProductMasterController as DashboardProductMasterController;
use App\Http\Controllers\Api\Dashboard\PromotionAssetController as DashboardPromotionAssetController;
use App\Http\Controllers\Api\Dashboard\PromotionController as DashboardPromotionController;
use App\Http\Controllers\Api\Dashboard\PushNotificationController as DashboardPushNotificationController;
use App\Http\Controllers\Api\Dashboard\SalesKpiController as DashboardSalesKpiController;
use App\Http\Controllers\Api\Dashboard\StoreReportController as DashboardStoreReportController;
use App\Http\Controllers\Api\Dashboard\SubscriberController as DashboardSubscriberController;
use App\Http\Controllers\Api\Dashboard\UserCountryAccessController as DashboardUserCountryAccessController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\PowerTranzController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\PushSendController;
use App\Http\Controllers\Api\StorefrontAccountController;
use App\Http\Controllers\Api\StorefrontAssetController;
use App\Http\Controllers\Api\StorefrontBrandController;
use App\Http\Controllers\Api\StorefrontCartController;
use App\Http\Controllers\Api\StorefrontCatalogController;
use App\Http\Controllers\Api\StorefrontCheckoutValidationController;
use App\Http\Controllers\Api\StorefrontEventController;
use App\Http\Controllers\Api\StorefrontHomeController;
use App\Http\Controllers\Api\StorefrontOrderController;
use App\Http\Controllers\Api\StorefrontProductAvailabilityController;
use App\Http\Controllers\Api\StorefrontProductController;
use App\Http\Controllers\Api\StorefrontPromotionController;
use App\Http\Controllers\Api\StorefrontRecommendationController;
use App\Http\Controllers\Api\StorefrontStoreController;
use App\Http\Controllers\Api\StorefrontShippingController;
use App\Http\Controllers\Api\StorefrontSubscriberController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/storefront/account/login', [StorefrontAccountController::class, 'login']);
Route::get('/storefront/account/registration-countries', [StorefrontAccountController::class, 'registrationCountries']);
Route::post('/storefront/account/register/{country}', [StorefrontAccountController::class, 'register'])
    ->where('country', '[A-Za-z]{2}');
Route::middleware('auth:sanctum')->prefix('storefront/account')->group(function () {
    Route::get('/', [StorefrontAccountController::class, 'show']);
    Route::put('/profile', [StorefrontAccountController::class, 'update']);
    Route::get('/locations/countries', [StorefrontAccountController::class, 'countries']);
    Route::get('/locations/states/{country}', [StorefrontAccountController::class, 'states'])->whereNumber('country');
    Route::get('/locations/cities/{state}', [StorefrontAccountController::class, 'cities'])->whereNumber('state');
    Route::post('/addresses', [StorefrontAccountController::class, 'storeAddress']);
    Route::put('/addresses/{address}', [StorefrontAccountController::class, 'updateAddress'])->whereNumber('address');
    Route::put('/addresses/{address}/primary', [StorefrontAccountController::class, 'makeAddressPrimary'])->whereNumber('address');
    Route::delete('/addresses/{address}', [StorefrontAccountController::class, 'destroyAddress'])->whereNumber('address');
    Route::post('/logout', [StorefrontAccountController::class, 'logout']);
});
Route::get('/storefront/home/{country}', [StorefrontHomeController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/assets/{country}', [StorefrontAssetController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/promotions/{country}', [StorefrontPromotionController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/stores/{country}', [StorefrontStoreController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/catalog/{country}', [StorefrontCatalogController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/brands/{country}/{brand}', [StorefrontBrandController::class, 'show'])
    ->where('country', '[A-Za-z]{2}')
    ->where('brand', '[A-Za-z0-9-]+');
Route::get('/storefront/product/{country}/{slug}', [StorefrontProductController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/product/{country}/{slug}/availability', [StorefrontProductAvailabilityController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::post('/storefront/checkout/validate', StorefrontCheckoutValidationController::class);
Route::post('/storefront/subscribers/{country}', [StorefrontSubscriberController::class, 'store'])
    ->where('country', '[A-Za-z]{2}');
Route::post('/storefront/events', [StorefrontEventController::class, 'store']);
Route::prefix('/storefront/recommendations/{country}')->where(['country' => '[A-Za-z]{2}'])->group(function () {
    Route::get('/recently-viewed', [StorefrontRecommendationController::class, 'recentlyViewed']);
    Route::get('/product/{product}', [StorefrontRecommendationController::class, 'product'])->whereNumber('product');
    Route::get('/cart', [StorefrontRecommendationController::class, 'cart']);
});
Route::get('/storefront/shipping/{country}/states', [StorefrontShippingController::class, 'locations'])->where('country', '[A-Za-z]{2}');
Route::get('/storefront/shipping/{country}/states/{state}/cities', [StorefrontShippingController::class, 'cities'])->where(['country' => '[A-Za-z]{2}', 'state' => '[0-9]+']);
Route::post('/storefront/shipping/{country}/quote', [StorefrontShippingController::class, 'quote'])->where('country', '[A-Za-z]{2}');
Route::post('/storefront/orders/{order}/payments/powertranz', [PowerTranzController::class, 'start'])->whereNumber('order')->middleware('throttle:5,1');
Route::get('/storefront/orders/{order}/payment-status', [PowerTranzController::class, 'status'])->whereNumber('order');
Route::post('/storefront/payments/powertranz/return/{country}/{token}', [PowerTranzController::class, 'handleReturn'])->where(['country' => '[A-Za-z]{2}', 'token' => '[A-Za-z0-9]{64}'])->middleware('throttle:30,1')->name('powertranz.return');
Route::prefix('/storefront/cart/{country}')->where(['country' => '[A-Za-z]{2}'])->group(function () {
    Route::get('/', [StorefrontCartController::class, 'show']);
    Route::post('/items', [StorefrontCartController::class, 'storeItem']);
    Route::patch('/items/{item}', [StorefrontCartController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/items/{item}', [StorefrontCartController::class, 'destroyItem'])->whereNumber('item');
    Route::post('/sync', [StorefrontCartController::class, 'sync']);
    Route::post('/merge', [StorefrontCartController::class, 'merge']);
    Route::post('/checkout/start', [StorefrontCartController::class, 'startCheckout']);
    Route::post('/orders', [StorefrontOrderController::class, 'store']);
});
Route::post('/storefront/fulfillment/{country}/preview', [StorefrontCartController::class, 'previewFulfillment'])->where('country', '[A-Za-z]{2}');
Route::put('/storefront/fulfillment/{country}', [StorefrontCartController::class, 'applyFulfillment'])->where('country', '[A-Za-z]{2}');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/productos', [ProductoController::class, 'listar']);
    Route::get('/pedido/detalle', [PedidoController::class, 'getPedidoById']);
    Route::post('/push/send', PushSendController::class);

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
        Route::post('/promotions/{promotion}/products', [DashboardPromotionController::class, 'replaceProducts']);
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
        Route::get('/sales/catalog', [DashboardSalesKpiController::class, 'catalog']);
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
        Route::get('/claims/export', [DashboardClaimController::class, 'export']);
        Route::post('/claims', [DashboardClaimController::class, 'store']);
        Route::post('/claims/{claim}', [DashboardClaimController::class, 'update']);
        Route::delete('/claims/{claim}', [DashboardClaimController::class, 'destroy']);
        Route::get('/subscribers', [DashboardSubscriberController::class, 'index']);
        Route::post('/subscribers', [DashboardSubscriberController::class, 'store']);
        Route::post('/subscribers/{subscriber}', [DashboardSubscriberController::class, 'update']);
        Route::delete('/subscribers/{subscriber}', [DashboardSubscriberController::class, 'destroy']);
        Route::get('/push-notifications', [DashboardPushNotificationController::class, 'index']);
        Route::post('/push-notifications', [DashboardPushNotificationController::class, 'store']);
        Route::delete('/push-notifications/{notification}', [DashboardPushNotificationController::class, 'destroy']);
        Route::get('/user-country-access', [DashboardUserCountryAccessController::class, 'index']);
        Route::get('/user-country-access/current', [DashboardUserCountryAccessController::class, 'current']);
        Route::get('/user-country-access/users', [DashboardUserCountryAccessController::class, 'users']);
        Route::post('/user-country-access', [DashboardUserCountryAccessController::class, 'store']);
        Route::delete('/user-country-access/{assignment}', [DashboardUserCountryAccessController::class, 'destroy']);
        Route::get('/reports/store/catalog', [DashboardStoreReportController::class, 'catalog']);
        Route::get('/reports/store/virtual-cut', [DashboardStoreReportController::class, 'virtualCut']);
        Route::get('/reports/store/pending-items', [DashboardStoreReportController::class, 'pendingItems']);
        Route::get('/reports/store/pending-items-by-order', [DashboardStoreReportController::class, 'pendingItemsByOrder']);
        Route::get('/reports/store/home-delivery', [DashboardStoreReportController::class, 'homeDelivery']);
        Route::get('/reports/store/home-delivery/export', [DashboardStoreReportController::class, 'homeDeliveryExport']);
        Route::get('/reports/accounting/3/count', [DashboardAccountingReportController::class, 'count3']);
        Route::get('/reports/accounting/3/export', [DashboardAccountingReportController::class, 'export3']);
        Route::get('/reports/accounting/sales-by-store', [DashboardAccountingReportController::class, 'salesByStore']);
        Route::get('/orders/reference', [DashboardOrderReferenceController::class, 'show']);
        Route::get('/orders/search', [DashboardOrderReferenceController::class, 'search']);
        Route::get('/orders/payment-attempts', [DashboardOrderReferenceController::class, 'paymentAttempts']);
        Route::get('/orders/refunds', [DashboardOrderReferenceController::class, 'refunds']);
        Route::get('/orders/product', [DashboardOrderReferenceController::class, 'product']);
        Route::post('/orders/data', [DashboardOrderReferenceController::class, 'updateData']);
        Route::post('/orders/shipping-management/lookup', [DashboardOrderReferenceController::class, 'shippingManagement']);
        Route::post('/orders/shipping-management', [DashboardOrderReferenceController::class, 'updateShippingManagement']);
        Route::post('/orders/lines/{line}', [DashboardOrderReferenceController::class, 'updateLine']);
        Route::post('/orders/process', [DashboardOrderReferenceController::class, 'process']);
        Route::post('/orders/packed-pickup', [DashboardOrderReferenceController::class, 'markPackedForPickup']);
        Route::post('/orders/route', [DashboardOrderReferenceController::class, 'markInRoute']);
        Route::post('/orders/deliver', [DashboardOrderReferenceController::class, 'deliver']);
        Route::post('/tasks/put-assets', DashboardAssetPublicationController::class);
    });
});
