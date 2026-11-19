<?php

use App\Models\Service\Service;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('account/profile', \App\Http\Controllers\API\ProfileController::class)->middleware('api');
Route::post('services', \App\Http\Controllers\API\ServiceController::class)->middleware('api');
Route::post('order/create', [\App\Http\Controllers\API\OrderController::class, 'create'])->middleware('api');
Route::post('order/status', [\App\Http\Controllers\API\OrderController::class, 'status'])->middleware('api');
Route::post('order/refill/create', [\App\Http\Controllers\API\OrderRefillController::class, 'create'])->middleware('api');
Route::post('order/refill/status', [\App\Http\Controllers\API\OrderRefillController::class, 'status'])->middleware('api');
Route::post('order/cancel/create', [\App\Http\Controllers\API\OrderCancelController::class, 'create'])->middleware('api');

// --- Endpoint ramah aplikasi mobile (login username+password -> api_key/secret_key) ---
// auth/login WAJIB tanpa middleware API (api_key/secret_key baru diperoleh SETELAH login),
// jadi middleware App\Http\Middleware\API dikecualikan khusus route ini (throttle tetap jalan).
Route::post('auth/login', [\App\Http\Controllers\API\AuthController::class, '__invoke'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:10,1');
Route::post('me', [\App\Http\Controllers\API\MeController::class, '__invoke'])->middleware('api');
Route::post('payment-methods', [\App\Http\Controllers\API\PaymentMethodController::class, '__invoke'])->middleware('api');
Route::post('deposits', [\App\Http\Controllers\API\DepositController::class, 'list'])->middleware('api');
Route::post('deposits/create', [\App\Http\Controllers\API\DepositController::class, 'store'])->middleware('api');
Route::post('orders', [\App\Http\Controllers\API\OrderListController::class, '__invoke'])->middleware('api');
