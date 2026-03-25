<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\LogViewerController;
use App\Http\Controllers\OrderController;

Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.index');
Route::post('/logs/login', [LogViewerController::class, 'login'])->name('logs.login');

Route::middleware(['log.viewer'])->group(function () {
    Route::get('/logs/view', [LogViewerController::class, 'show'])->name('logs.show');
    Route::get('/logs/logout', [LogViewerController::class, 'logout'])->name('logs.logout');
    
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
});
