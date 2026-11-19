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
use App\Models\Locale;
use App\Models\User\User;
use App\Models\User\UserActivityLog;
use App\Models\User\UserSigninLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login — login aplikasi via username/email/whatsapp + password.
     * Sukses mengembalikan api_key & secret_key sehingga app tidak perlu scraping UI web.
     * Stateless murni: verifikasi manual via Hash::check, tanpa session/Auth::attempt.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()->all(),
            ], 400, [], JSON_PRETTY_PRINT);
        }

        $throttleKey = 'app-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'status'  => false,
                'message' => 'Terlalu banyak percobaan. Coba lagi dalam ' . $seconds . ' detik.',
                'errors'  => ['Coba lagi dalam ' . $seconds . ' detik.'],
            ], 429, [], JSON_PRETTY_PRINT);
        }

        $username = (string) $request->input('username');
        $password = (string) $request->input('password');

        $user = null;
        if (is_numeric($username)) {
            // Identitas numerik = whatsapp (sama dengan alur web SigninController).
            $user = User::where('whatsapp', $username)->first();
        } elseif (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $username)->first();
        } else {
            $user = User::where('username', $username)->first();
        }
        if (!$user || !Hash::check($password, $user->password)) {
            // Pesan identik untuk user-tak-ada & password-salah: jangan bocorkan mana yang keliru.
            RateLimiter::hit($throttleKey, 300);
            return response()->json([
                'status'  => false,
                'message' => 'Kredensial salah.',
                'errors'  => ['Username atau password tidak sesuai.'],
            ], 422, [], JSON_PRETTY_PRINT);
        }
        if ((int) $user->status !== 1) {
            // Akun nonaktif TIDAK menambah hit rate limiter.
            return response()->json([
                'status'  => false,
                'message' => 'Akun dinonaktifkan.',
                'errors'  => ['Hubungi admin.'],
            ], 423, [], JSON_PRETTY_PRINT);
        }

        $api = is_array($user->api) ? $user->api : [];
        $apiChanged = false;
        if (empty($api['api_key'])) {
            $api['api_key'] = generate_api_key();
            $apiChanged = true;
        }
        if (empty($api['secret_key'])) {
            $api['secret_key'] = bin2hex(random_bytes(16));
            $apiChanged = true;
        }
        if ($apiChanged) {
            $user->update(['api' => $api]);
            $user->refresh();
        }

        UserSigninLog::create([
            'user_id'     => $user->id,
            'ip_address'  => (string) $request->ip(),
            'user_agent'  => (string) $request->header('User-Agent', ''),
        ]);
        UserActivityLog::create([
            'user_id'        => $user->id,
            'ip_address'     => (string) $request->ip(),
            'user_agent'     => (string) $request->header('User-Agent', ''),
            'content_key'    => 'auth:log.signin-to-dashboard',
            'content_params' => (object) [],
        ]);

        RateLimiter::clear($throttleKey);

        $locale = Locale::find(1);
        $apiFresh = $user->api;

        return response()->json([
            'status' => true,
            'data'   => [
                'api_key'    => $apiFresh['api_key'],
                'secret_key' => $apiFresh['secret_key'],
                'user'       => [
                    'username'         => $user->username,
                    'full_name'        => $user->full_name,
                    'email'            => $user->email,
                    'whatsapp'         => $user->whatsapp,
                    'balance'          => round((float) $user->balance * $locale->usd_rate, (int) $locale->max_decimal_digits),
                    'service_discount' => (float) $user->service_discount,
                    'currency'         => $locale->currency_code,
                    'joined_at'        => date('Y-m-d', strtotime($user->created_at)),
                ],
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
