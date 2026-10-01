<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SupportRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/', [SupportRequestController::class, 'index'])->name('home');
    Route::get('/requests/stats', [SupportRequestController::class, 'stats'])->name('requests.stats');
    Route::get('/requests/{supportRequest}', [SupportRequestController::class, 'show'])->name('requests.show');
    Route::post('/requests/{supportRequest}/messages', [SupportRequestController::class, 'storeMessage'])->name('requests.messages.store');
    Route::get('/messages/{message}/image', [MediaController::class, 'image'])->name('messages.image');
    Route::get('/messages/{message}/file', [MediaController::class, 'file'])->name('messages.file');
    Route::post('/requests/{supportRequest}/close', [SupportRequestController::class, 'close'])->name('requests.close');
});
