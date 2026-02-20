<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhook\ShopifyWebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Shopify Webhooks
Route::post('/webhooks/shopify/orders', [ShopifyWebhookController::class, 'handleOrderCreate']);

// Debug / Manual Test Routes
Route::prefix('debug')->group(function () {
    Route::get('/turum-auth', [\App\Http\Controllers\DebugController::class, 'testAuth']);
    Route::get('/get_all', [\App\Http\Controllers\DebugController::class, 'getAllProducts']);
    Route::get('/reservations', [\App\Http\Controllers\DebugController::class, 'getAllReservations']);
    Route::get('/turum-product/{sku}', [\App\Http\Controllers\DebugController::class, 'testProduct']);
    Route::get('/db-mapping/{sku}', [\App\Http\Controllers\DebugController::class, 'checkMapping']);
});
