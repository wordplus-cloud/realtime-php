<?php

use App\Http\Controllers\ShopController;
use App\Http\Controllers\SignInController;
use App\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [SignInController::class, 'show'])->name('login');
Route::post('/login', [SignInController::class, 'store']);
Route::post('/logout', [SignInController::class, 'destroy'])->name('logout');

// The live show's token, for its viewers signed in or not (TokenController).
Route::get('/api/show-token', [TokenController::class, 'show'])->name('tokens.show');

Route::middleware('auth')->group(function () {
    Route::get('/', [ShopController::class, 'show']);
    // On a demo, a window as one of the shop people (App\Support\Demo).
    Route::get('/as/{token}', [ShopController::class, 'show']);
    Route::post('/orders/{order}/status', [ShopController::class, 'status'])->name('orders.status');
    Route::get('/orders/{order}/wire', [ShopController::class, 'wire'])->name('orders.wire');
    Route::get('/chat/messages', [ShopController::class, 'messages'])->name('chat.messages');
    // The tokens that start a call and the daily meeting (TokenController).
    Route::get('/api/call-token', [TokenController::class, 'call'])->name('tokens.call');
    Route::get('/api/meeting-token', [TokenController::class, 'meeting'])->name('tokens.meeting');
});
