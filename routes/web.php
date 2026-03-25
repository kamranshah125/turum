<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\LogViewerController;

Route::get('/logs', [LogViewerController::class, 'index'])->name('logs.index');
Route::post('/logs/login', [LogViewerController::class, 'login'])->name('logs.login');
Route::get('/logs/view', [LogViewerController::class, 'show'])->name('logs.show')->middleware('log.viewer');
Route::get('/logs/logout', [LogViewerController::class, 'logout'])->name('logs.logout')->middleware('log.viewer');
