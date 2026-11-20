<?php

/*
|--------------------------------------------------------------------------
| Source by jhonroot
|--------------------------------------------------------------------------
| * Facebook : https://fb.me/jhonroott
| * Whatsapp : https://wa.me/6285349680016
| * Telegram : https://t.me/jhonroott
| * Web      : www.jhonroot.dev
|--------------------------------------------------------------------------
*/

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Deposit\Deposit;
use App\Models\Locale;
use App\Models\Order\Order;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MeController extends Controller
{
    /**
     * POST /api/me — profil user berdasarkan kredensial api_key + secret_key.
     * Bentuk data sama dengan ProfileController V1, ditambah service_discount & status.
     * Rate limit login-like: guard serupa auth/login supaya kredensial tidak ditebak di sini.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $throttleKey = 'app-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status'  => false,
                'message' => 'Terlalu banyak percobaan. Coba lagi dalam ' . $seconds . ' detik.',
                'errors'  => ['Coba lagi dalam ' . $seconds . ' detik.'],
            ], 429, [], JSON_PRETTY_PRINT);
        }

        $user = User::whereJsonContains('api->api_key', (string) $request->input('api_key'))
            ->whereJsonContains('api->secret_key', (string) $request->input('secret_key'))
            ->first();

        if (!$user) {
            RateLimiter::hit($throttleKey, 300);
            return response()->json([
                'status'  => false,
                'message' => 'Account not found.',
                'errors'  => ['Account not found.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        RateLimiter::clear($throttleKey);

        $locale = Locale::find(1);

        return response()->json([
            'status' => true,
            'data'   => [
                'username'         => $user->username,
                'full_name'        => $user->full_name,
                'email'            => $user->email,
                'whatsapp'         => $user->whatsapp,
                'balance'          => round((float) $user->balance * $locale->usd_rate, (int) $locale->max_decimal_digits),
                'service_discount' => (float) $user->service_discount,
                'status'           => (int) $user->status,
                'orders'           => round(
                    Order::where([
                        ['user_id', $user->id],
                        ['status', 'completed'],
                    ])->sum('price') * $locale->usd_rate,
                    (int) $locale->max_decimal_digits
                ),
                'deposits'         => round(
                    Deposit::where([
                        ['user_id', $user->id],
                        ['status', 'success'],
                    ])->sum('amount') * $locale->usd_rate,
                    (int) $locale->max_decimal_digits
                ),
                'joined_at'        => date('Y-m-d', strtotime($user->created_at)),
                'kurs_rate'        => (float) ($locale->usd_rate_buy > 0 ? $locale->usd_rate_buy : $locale->usd_rate),
                'kurs_display'     => (float) $locale->usd_rate,
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
