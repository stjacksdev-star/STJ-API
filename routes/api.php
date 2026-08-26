<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CheckoutEventController;
use App\Http\Controllers\Api\Dashboard\AccountingReportController as DashboardAccountingReportController;
use App\Http\Controllers\Api\Dashboard\AppointmentController as DashboardAppointmentController;
use App\Http\Controllers\Api\Dashboard\AssetPublicationController as DashboardAssetPublicationController;
use App\Http\Controllers\Api\Dashboard\ClaimController as DashboardClaimController;
use App\Http\Controllers\Api\Dashboard\CollectionAssetController as DashboardCollectionAssetController;
use App\Http\Controllers\Api\Dashboard\CollectionController as DashboardCollectionController;
use App\Http\Controllers\Api\Dashboard\CouponController as DashboardCouponController;
use App\Http\Controllers\Api\Dashboard\OrderReferenceController as DashboardOrderReferenceController;
use App\Http\Controllers\Api\Dashboard\ProductCategoryController as DashboardProductCategoryController;
use App\Http\Controllers\Api\Dashboard\ProductCountryController as DashboardProductCountryController;
use App\Http\Controllers\Api\Dashboard\ProductMasterController as DashboardProductMasterController;
use App\Http\Controllers\Api\Dashboard\ProductPerformanceReportController as DashboardProductPerformanceReportController;
use App\Http\Controllers\Api\Dashboard\PromotionAssetController as DashboardPromotionAssetController;
use App\Http\Controllers\Api\Dashboard\PromotionController as DashboardPromotionController;
use App\Http\Controllers\Api\Dashboard\PushNotificationController as DashboardPushNotificationController;
use App\Http\Controllers\Api\Dashboard\SalesKpiController as DashboardSalesKpiController;
use App\Http\Controllers\Api\Dashboard\StoreReportController as DashboardStoreReportController;
use App\Http\Controllers\Api\Dashboard\SubscriberController as DashboardSubscriberController;
use App\Http\Controllers\Api\Dashboard\UserCountryAccessController as DashboardUserCountryAccessController;
use App\Http\Controllers\Api\Mobile\MobileAddressController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\MobileCategoryController;
use App\Http\Controllers\Api\Mobile\MobileCartController;
use App\Http\Controllers\Api\Mobile\MobileProductController;
use App\Http\Controllers\Api\Mobile\MobileStoreController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\PowerTranzController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\PushSendController;
use App\Http\Controllers\Api\StorefrontAccountController;
use App\Http\Controllers\Api\StorefrontAssetController;
use App\Http\Controllers\Api\StorefrontBestSellerController;
use App\Http\Controllers\Api\StorefrontBrandController;
use App\Http\Controllers\Api\StorefrontCartController;
use App\Http\Controllers\Api\StorefrontCatalogController;
use App\Http\Controllers\Api\StorefrontCheckoutCatalogController;
use App\Http\Controllers\Api\StorefrontCheckoutValidationController;
use App\Http\Controllers\Api\StorefrontCollectionController;
use App\Http\Controllers\Api\StorefrontContactController;
use App\Http\Controllers\Api\StorefrontCouponLandingController;
use App\Http\Controllers\Api\StorefrontEventController;
use App\Http\Controllers\Api\StorefrontFavoriteController;
use App\Http\Controllers\Api\StorefrontHomeController;
use App\Http\Controllers\Api\StorefrontOrderController;
use App\Http\Controllers\Api\StorefrontOrderTrackingController;
use App\Http\Controllers\Api\StorefrontProductAvailabilityController;
use App\Http\Controllers\Api\StorefrontProductController;
use App\Http\Controllers\Api\StorefrontPromotionController;
use App\Http\Controllers\Api\StorefrontPromotionLandingController;
use App\Http\Controllers\Api\StorefrontRecommendationController;
use App\Http\Controllers\Api\StorefrontShippingController;
use App\Http\Controllers\Api\StorefrontStoreController;
use App\Http\Controllers\Api\StorefrontSubscriberController;
use App\Http\Controllers\Api\StorefrontWebPushSubscriptionController;
use App\Http\Controllers\Api\WebPushClickController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/mobile/v1/auth/login', [MobileAuthController::class, 'login'])
    ->middleware('throttle:10,1');
Route::middleware('auth:sanctum')->prefix('mobile/v1/auth')->group(function () {
    Route::get('/session', [MobileAuthController::class, 'session']);
    Route::post('/logout', [MobileAuthController::class, 'logout']);
});
Route::middleware('auth:sanctum')->prefix('mobile/v1/account')->group(function () {
    Route::get('/', [MobileAuthController::class, 'account']);
    Route::put('/', [MobileAuthController::class, 'updateAccount'])->middleware('throttle:30,1');
    Route::get('/addresses', [MobileAddressController::class, 'index']);
    Route::get('/addresses/primary', [MobileAddressController::class, 'primary']);
    Route::post('/addresses', [MobileAddressController::class, 'store'])->middleware('throttle:30,1');
    Route::put('/addresses/{address}/primary', [MobileAddressController::class, 'makePrimary'])->whereNumber('address');
});
Route::middleware(['auth:sanctum', 'throttle:120,1'])->prefix('mobile/v1/cart')->group(function () {
    Route::get('/', [MobileCartController::class, 'show']);
    Route::post('/items', [MobileCartController::class, 'store']);
    Route::patch('/items/{item}', [MobileCartController::class, 'update'])->whereNumber('item');
    Route::put('/selection', [MobileCartController::class, 'select']);
    Route::post('/shipping-quote', [MobileCartController::class, 'quoteShipping']);
    Route::post('/validate', [MobileCartController::class, 'validateCheckout']);
    Route::post('/order', [MobileCartController::class, 'order'])->middleware('throttle:20,1');
    Route::get('/coupons', [MobileCartController::class, 'coupons']);
    Route::post('/coupons', [MobileCartController::class, 'storeCoupon']);
    Route::delete('/coupons/{application}', [MobileCartController::class, 'destroyCoupon'])->whereNumber('application');
});
Route::get('/mobile/v1/catalog/categories', [MobileCategoryController::class, 'index'])
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/categories/search', [MobileCategoryController::class, 'search'])
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/categories/{category}/subcategories/{type}', [MobileCategoryController::class, 'subcategories'])
    ->whereNumber('category')
    ->where('type', '[A-Za-z0-9_-]+')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/categories/{category}/size-guide', [MobileCategoryController::class, 'sizeGuide'])
    ->whereNumber('category')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/categories/{category}', [MobileCategoryController::class, 'show'])
    ->whereNumber('category')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/stores', [MobileStoreController::class, 'index'])
    ->middleware('throttle:120,1');
Route::post('/mobile/v1/catalog/favorites', [MobileProductController::class, 'setFavorite'])
    ->middleware('throttle:60,1');
Route::get('/mobile/v1/catalog/favorites', [MobileProductController::class, 'favorites'])
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/products', [MobileProductController::class, 'index'])
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/products/{sku}/availability', [MobileProductController::class, 'sizes'])
    ->where('sku', '[A-Za-z0-9._-]+')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/products/{product}/suggestions', [MobileProductController::class, 'suggestions'])
    ->whereNumber('product')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/products/{product}/photos', [MobileProductController::class, 'photos'])
    ->whereNumber('product')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/products/{product}/favorite-status', [MobileProductController::class, 'favoriteStatus'])
    ->whereNumber('product')
    ->middleware('throttle:120,1');
Route::get('/mobile/v1/catalog/products/{product}', [MobileProductController::class, 'show'])
    ->whereNumber('product')
    ->middleware('throttle:120,1');
Route::post('/mobile/v1/catalog/products/filter', [MobileProductController::class, 'filter'])
    ->middleware('throttle:120,1');
Route::post('/mobile/v1/catalog/products/jack-co/filter', [MobileProductController::class, 'filterJackCo'])
    ->middleware('throttle:120,1');
Route::post('/mobile/v1/catalog/products/basikos/filter', [MobileProductController::class, 'filterBasikos'])
    ->middleware('throttle:120,1');
Route::post('/storefront/account/login', [StorefrontAccountController::class, 'login']);
Route::post('/storefront/account/forgot-password/{country}', [StorefrontAccountController::class, 'forgotPassword'])
    ->where('country', '[A-Za-z]{2}')
    ->middleware('throttle:10,1');
Route::post('/storefront/account/reset-password', [StorefrontAccountController::class, 'resetPassword'])
    ->middleware('throttle:10,1');
Route::get('/storefront/account/registration-countries', [StorefrontAccountController::class, 'registrationCountries']);
Route::post('/storefront/account/register/{country}', [StorefrontAccountController::class, 'register'])
    ->where('country', '[A-Za-z]{2}');
Route::middleware('auth:sanctum')->prefix('storefront/account')->group(function () {
    Route::get('/', [StorefrontAccountController::class, 'show']);
    Route::get('/orders/{reference}', [StorefrontAccountController::class, 'order']);
    Route::post('/password-change-link', [StorefrontAccountController::class, 'requestPasswordChange'])
        ->middleware('throttle:5,1');
    Route::delete('/', [StorefrontAccountController::class, 'destroy'])
        ->middleware('throttle:3,1');
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
Route::post('/storefront/contact/{country}', [StorefrontContactController::class, 'store'])
    ->where('country', '[A-Za-z]{2}')
    ->middleware('throttle:3,1');
Route::get('/storefront/assets/{country}', [StorefrontAssetController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/best-sellers/{country}', [StorefrontBestSellerController::class, 'index'])
    ->where('country', '[A-Za-z0-9]+');
Route::get('/storefront/promotions/{country}', [StorefrontPromotionController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/promotion/{country}/{promotion}', [StorefrontPromotionLandingController::class, 'show'])
    ->where('country', '[A-Za-z]{2}')
    ->whereNumber('promotion');
Route::get('/storefront/stores/{country}', [StorefrontStoreController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::post('/storefront/orders/{country}/track', [StorefrontOrderTrackingController::class, 'show'])
    ->where('country', '[A-Za-z]{2}')
    ->middleware('throttle:10,1');
Route::get('/storefront/catalog/{country}', [StorefrontCatalogController::class, 'index'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/collections/{country}/{collection}', [StorefrontCollectionController::class, 'show'])
    ->where('country', '[A-Za-z]{2}')
    ->whereNumber('collection');
Route::get('/storefront/coupons/{country}/{header}', [StorefrontCouponLandingController::class, 'show'])->where(['country' => '[A-Za-z]{2}', 'header' => '[0-9]+']);
Route::get('/storefront/brands/{country}/{brand}', [StorefrontBrandController::class, 'show'])
    ->where('country', '[A-Za-z]{2}')
    ->where('brand', '[A-Za-z0-9-]+');
Route::get('/storefront/product/{country}/{slug}', [StorefrontProductController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::get('/storefront/product/{country}/{slug}/availability', [StorefrontProductAvailabilityController::class, 'show'])
    ->where('country', '[A-Za-z]{2}');
Route::post('/storefront/checkout/validate', StorefrontCheckoutValidationController::class);
Route::post('/storefront/checkout/{country}/events', CheckoutEventController::class)->where('country', '[A-Za-z]{2}')->middleware('throttle:120,1');
Route::get('/storefront/checkout/{country}/catalogs', [StorefrontCheckoutCatalogController::class, 'index'])->where('country', '[A-Za-z]{2}');
Route::get('/storefront/checkout/catalogs/countries/{country}/states', [StorefrontCheckoutCatalogController::class, 'states'])->whereNumber('country');
Route::get('/storefront/checkout/catalogs/countries/{country}/states/{state}/cities', [StorefrontCheckoutCatalogController::class, 'cities'])->whereNumber(['country', 'state']);
Route::post('/storefront/subscribers/{country}', [StorefrontSubscriberController::class, 'store'])
    ->where('country', '[A-Za-z]{2}');
Route::prefix('/storefront/push-subscriptions/{country}')->where(['country' => '[A-Za-z]{2}'])->group(function () {
    Route::post('/', [StorefrontWebPushSubscriptionController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/', [StorefrontWebPushSubscriptionController::class, 'destroy'])->middleware('throttle:20,1');
});
Route::get('/storefront/push/deliveries/{delivery}/click', WebPushClickController::class)
    ->whereNumber('delivery')
    ->middleware(['signed:relative', 'throttle:60,1'])
    ->name('storefront.push.click');
Route::post('/storefront/events', [StorefrontEventController::class, 'store']);
Route::prefix('/storefront/favorites/{country}')->where(['country' => '[A-Za-z]{2}'])->group(function () {
    Route::get('/', [StorefrontFavoriteController::class, 'index']);
    Route::post('/', [StorefrontFavoriteController::class, 'store']);
    Route::delete('/{product}', [StorefrontFavoriteController::class, 'destroy'])->whereNumber('product');
});
Route::prefix('/storefront/recommendations/{country}')->where(['country' => '[A-Za-z]{2}'])->group(function () {
    Route::get('/recently-viewed', [StorefrontRecommendationController::class, 'recentlyViewed']);
    Route::get('/product/{product}', [StorefrontRecommendationController::class, 'product'])->whereNumber('product');
    Route::get('/cart', [StorefrontRecommendationController::class, 'cart']);
});
Route::get('/storefront/shipping/{country}/states', [StorefrontShippingController::class, 'locations'])->where('country', '[A-Za-z]{2}');
Route::get('/storefront/shipping/{country}/states/{state}/cities', [StorefrontShippingController::class, 'cities'])->where(['country' => '[A-Za-z]{2}', 'state' => '[0-9]+']);
Route::post('/storefront/shipping/{country}/quote', [StorefrontShippingController::class, 'quote'])->where('country', '[A-Za-z]{2}');
Route::post('/storefront/orders/{order}/payments/powertranz', [PowerTranzController::class, 'start'])->whereNumber('order')->middleware('throttle:powertranz-start');
Route::get('/storefront/payments/powertranz/challenge/{token}', [PowerTranzController::class, 'challenge'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:20,1')->name('powertranz.challenge');
Route::get('/storefront/orders/{order}/payment-status', [PowerTranzController::class, 'status'])->whereNumber('order');
Route::post('/storefront/payments/powertranz/return/{country}/{token}', [PowerTranzController::class, 'handleReturn'])->where(['country' => '[A-Za-z]{2}', 'token' => '[A-Za-z0-9]{64}'])->middleware('throttle:30,1')->name('powertranz.return');
Route::prefix('/storefront/cart/{country}')->where(['country' => '[A-Za-z]{2}'])->group(function () {
    Route::get('/', [StorefrontCartController::class, 'show']);
    Route::get('/gift-boxes', [StorefrontCartController::class, 'giftBoxes']);
    Route::post('/items', [StorefrontCartController::class, 'storeItem']);
    Route::patch('/items/{item}', [StorefrontCartController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/items/{item}', [StorefrontCartController::class, 'destroyItem'])->whereNumber('item');
    Route::post('/sync', [StorefrontCartController::class, 'sync']);
    Route::post('/coupons', [StorefrontCartController::class, 'storeCoupon']);
    Route::get('/coupons/available', [StorefrontCartController::class, 'availableCoupons']);
    Route::post('/coupons/revalidate', [StorefrontCartController::class, 'revalidateCoupons']);
    Route::delete('/coupons/{application}', [StorefrontCartController::class, 'destroyCoupon'])->whereNumber('application');
    Route::post('/validate', [StorefrontCartController::class, 'validateForCheckout']);
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
        Route::get('/reports/product-performance', DashboardProductPerformanceReportController::class);
        Route::get('/reports/product-performance/export', [DashboardProductPerformanceReportController::class, 'export']);
        Route::get('/collections', [DashboardCollectionController::class, 'index']);
        Route::get('/coupons', [DashboardCouponController::class, 'index']);
        Route::get('/coupons/catalogs', [DashboardCouponController::class, 'catalogs']);
        Route::get('/coupons/usage-report', [DashboardCouponController::class, 'usageReport']);
        Route::post('/coupons', [DashboardCouponController::class, 'store']);
        Route::put('/coupons/{coupon}', [DashboardCouponController::class, 'update'])->whereNumber('coupon');
        Route::post('/coupons/{coupon}', [DashboardCouponController::class, 'update'])->whereNumber('coupon');
        Route::patch('/coupons/{coupon}/status', [DashboardCouponController::class, 'status'])->whereNumber('coupon');
        Route::post('/collections', [DashboardCollectionController::class, 'store']);
        Route::post('/collections/{collection}', [DashboardCollectionController::class, 'update']);
        Route::get('/collections/{collection}/assets', [DashboardCollectionAssetController::class, 'index']);
        Route::post('/collections/{collection}/assets', [DashboardCollectionAssetController::class, 'store']);
        Route::post('/assets/{asset}', [DashboardCollectionAssetController::class, 'update']);
        Route::get('/promotions', [DashboardPromotionController::class, 'index']);
        Route::get('/promotions/stores', [DashboardPromotionController::class, 'stores']);
        Route::get('/promotions/{promotion}', [DashboardPromotionController::class, 'show']);
        Route::post('/promotions', [DashboardPromotionController::class, 'store']);
        Route::post('/promotions/{promotion}/stores', [DashboardPromotionController::class, 'updateStores']);
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
