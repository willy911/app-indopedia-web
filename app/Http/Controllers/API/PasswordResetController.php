<?php

/*
|--------------------------------------------------------------------------
| Source by jhonroot
|--------------------------------------------------------------------------
| * Facebook : https://fb.me/jhonroott  
| * Whatsapp : https://wa.me/6285349680016  
| * Telegram : https://t.me/jhondelton   
| * Web      : www.jhonroot.dev
|--------------------------------------------------------------------------
*/

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\User\Auth\SendResetPasswordLink;
use App\Models\User\User;
use App\Models\User\UserActivityLog;
use App\Models\User\UserPasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class PasswordResetController extends Controller
{
    /**
     * POST /api/auth/forgot-password — minta link reset password via email (PUBLIK).
     * Port PasswordResetController@storeRequest pada panel web: rate limit 1
     * permintaan per 30 detik per IP, hanya email terverifikasi & akun aktif,
     * token pending yang masih ada dipakai ulang (tidak membuat token baru).
     * Link reset mengarah ke APP_FRONTEND_URL (config services.app.frontend_url).
     */
    public function forgot(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'email' => [
                'required',
                'email:rfc',
                Rule::exists('users', 'email')->whereNotNull('email_verified_at')->where('status', '1'),
            ],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
            'email.exists'   => 'Email tidak ditemukan atau belum terverifikasi.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Email tidak ditemukan atau belum terverifikasi.',
                'errors'  => $validator->errors()->all(),
            ], 422, [], JSON_PRETTY_PRINT);
        }

        if (!RateLimiter::attempt('limit: ' . $request->ip(), 1, function () {}, 30)) {
            $seconds = RateLimiter::availableIn('limit: ' . $request->ip());
            return response()->json([
                'status'  => false,
                'message' => 'Terlalu banyak percobaan. Coba lagi dalam ' . $seconds . ' detik.',
                'errors'  => ['Coba lagi dalam ' . $seconds . ' detik.'],
            ], 429, [], JSON_PRETTY_PRINT);
        }

        $user = User::where([
            ['email', (string) $request->input('email')],
            ['email_verified_at', '<>', null],
            ['status', '1'],
        ])->first();
        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Email tidak ditemukan atau belum terverifikasi.',
                'errors'  => ['Email tidak ditemukan atau belum terverifikasi.'],
            ], 422, [], JSON_PRETTY_PRINT);
        }

        $token = md5($user->email . '-' . $user->username . '-' . rand(1, 99999));
        $checkRecoveryPasswordEmail = $recoveryPasswordEmail = UserPasswordReset::where('user_id', $user->id)->first();
        if (!$checkRecoveryPasswordEmail) {
            $insertRecoveryPasswordEmail = $recoveryPasswordEmail = UserPasswordReset::create([
                'user_id' => $user->id,
                'token'   => $token,
            ]);
        } else {
            $token = $recoveryPasswordEmail->token;
        }

        Mail::to($user->email)->queue(new SendResetPasswordLink((object) [
            'user'  => $user,
            'token' => $token,
        ]));

        return response()->json([
            'status'  => true,
            'message' => 'Email reset password dikirim.',
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/auth/reset-password — ubah password memakai token dari email (PUBLIK).
     * Port PasswordResetController@storeReset pada panel web: password di-hash,
     * token dihapus sekali pakai, dan aktivitas tercatat sebagai 'auth:log.update-password'.
     */
    public function reset(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'token'        => ['required', 'string'],
            'new_password' => ['required', 'min:6'],
        ], [
            'token.required'        => 'Token reset wajib diisi.',
            'new_password.required' => 'Password baru wajib diisi.',
            'new_password.min'      => 'Password baru minimal 6 karakter.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()->all(),
            ], 400, [], JSON_PRETTY_PRINT);
        }

        $userPasswordReset = UserPasswordReset::where('token', (string) $request->input('token'))->first();
        if (!$userPasswordReset) {
            return response()->json([
                'status'  => false,
                'message' => 'Token reset tidak valid atau kedaluwarsa.',
                'errors'  => ['Token reset tidak valid atau kedaluwarsa.'],
            ], 422, [], JSON_PRETTY_PRINT);
        }

        $user = $userPasswordReset->user;
        $user->update([
            'password' => escape_input(Hash::make((string) $request->input('new_password'))),
        ]);
        $userPasswordReset->delete();
        UserActivityLog::create([
            'user_id'        => $user->id,
            'ip_address'     => (string) $request->ip(),
            'user_agent'     => (string) $request->header('User-Agent', ''),
            'content_key'    => 'auth:log.update-password',
            'content_params' => (object) [],
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Password berhasil diubah.',
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
