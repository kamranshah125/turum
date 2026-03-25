<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\LogViewerController;

Route::prefix('logs')->group(function () {
    Route::get('/', [LogViewerController::class, 'index'])->name('logs.index');
    Route::post('/login', [LogViewerController::class, 'login'])->name('logs.login');
    
    Route::middleware(['log.viewer'])->group(function () {
        Route::get('/view', [LogViewerController::class, 'show'])->name('logs.show');
        Route::get('/logout', [LogViewerController::class, 'logout'])->name('logs.logout');
    });
});
