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
