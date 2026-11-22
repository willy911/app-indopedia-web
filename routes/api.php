<?php

use App\Models\Service\Service;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('account/profile', \App\Http\Controllers\API\ProfileController::class)->middleware('api');
Route::post('services', \App\Http\Controllers\API\ServiceController::class)->middleware('api');
// Detail layanan + ringkasan pesanan + contoh target (kredensial api_key + secret_key).
Route::post('services/detail', [\App\Http\Controllers\API\ServiceDetailController::class, '__invoke'])->middleware('api');
// Best seller dari pesanan riil bulan ini (kredensial api_key + secret_key, sama seperti /services).
Route::post('services/best-sellers', [\App\Http\Controllers\API\ServiceController::class, 'bestSellers'])->middleware('api');
Route::post('order/create', [\App\Http\Controllers\API\OrderController::class, 'create'])->middleware('api');
Route::post('order/status', [\App\Http\Controllers\API\OrderController::class, 'status'])->middleware('api');
Route::post('order/refill/create', [\App\Http\Controllers\API\OrderRefillController::class, 'create'])->middleware('api');
Route::post('order/refill/status', [\App\Http\Controllers\API\OrderRefillController::class, 'status'])->middleware('api');
Route::post('order/cancel/create', [\App\Http\Controllers\API\OrderCancelController::class, 'create'])->middleware('api');
Route::post('order/detail', [\App\Http\Controllers\API\OrderListController::class, 'detail'])->middleware('api');

// --- Endpoint ramah aplikasi mobile (login username+password -> api_key/secret_key) ---
// auth/login WAJIB tanpa middleware API (api_key/secret_key baru diperoleh SETELAH login),
// jadi middleware App\Http\Middleware\API dikecualikan khusus route ini (throttle tetap jalan).
Route::post('auth/login', [\App\Http\Controllers\API\AuthController::class, '__invoke'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:10,1');
Route::post('me', [\App\Http\Controllers\API\MeController::class, '__invoke'])->middleware('api');
Route::post('affiliate', [\App\Http\Controllers\API\AffiliateController::class, '__invoke'])->middleware('api');
Route::post('payment-methods', [\App\Http\Controllers\API\PaymentMethodController::class, '__invoke'])->middleware('api');
Route::post('deposits', [\App\Http\Controllers\API\DepositController::class, 'list'])->middleware('api');
Route::post('deposits/create', [\App\Http\Controllers\API\DepositController::class, 'store'])->middleware('api');
Route::post('deposit/detail', [\App\Http\Controllers\API\DepositController::class, 'detail'])->middleware('api');
Route::post('orders', [\App\Http\Controllers\API\OrderListController::class, '__invoke'])->middleware('api');

// --- Endpoint rating/ulasan layanan (kredensial api_key + secret_key) ---
Route::post('reviews', [\App\Http\Controllers\API\ReviewController::class, 'store'])->middleware('api');
Route::post('reviews/mine', [\App\Http\Controllers\API\ReviewController::class, 'mine'])->middleware('api');
Route::post('reviews/eligible', [\App\Http\Controllers\API\ReviewController::class, 'eligible'])->middleware('api');
Route::post('services/reviews', [\App\Http\Controllers\API\ReviewController::class, 'serviceReviews'])->middleware('api');

// --- Endpoint aplikasi: signup, reset password, monitoring (PUBLIK, tanpa kredensial) ---
// Semua route di bawah ini diakses SEBELUM user punya api_key/secret_key (atau tanpa
// kredensial sama sekali untuk monitoring publik), jadi middleware
// App\Http\Middleware\API (yang di-append ke seluruh grup api) dikecualikan per-route.
Route::post('auth/signup', [\App\Http\Controllers\API\SignupController::class, '__invoke'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:5,1');
Route::post('auth/forgot-password', [\App\Http\Controllers\API\PasswordResetController::class, 'forgot'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:3,1');
Route::post('auth/reset-password', [\App\Http\Controllers\API\PasswordResetController::class, 'reset'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:5,1');
Route::post('monitoring', [\App\Http\Controllers\API\MonitoringController::class, 'index'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:30,1');
Route::post('monitoring/services', [\App\Http\Controllers\API\MonitoringController::class, 'services'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:30,1');
Route::post('news', [\App\Http\Controllers\API\NewsController::class, '__invoke'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:30,1');
Route::post('news/popup', [\App\Http\Controllers\API\NewsController::class, 'popup'])->withoutMiddleware([\App\Http\Middleware\API::class])->middleware('throttle:30,1');
