<?php

use App\Http\Controllers\WebSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('hub'));

// What an invitation link opens in a browser: how to use it in Comitiva.
Route::get('/invite/{token}', fn (string $token) => view('invite', ['token' => $token]))->name('invite');

// Session sign-in for the web UI (Phase 9); desktops use API tokens instead.
Route::post('/login', [WebSessionController::class, 'login'])->middleware('throttle:auth');
Route::post('/logout', [WebSessionController::class, 'logout']);
