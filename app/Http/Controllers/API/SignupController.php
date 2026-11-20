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
use App\Models\Locale;
use App\Models\User\User;
use App\Models\User\UserActivityLog;
use App\Models\User\UserSignupLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
class SignupController extends Controller
{
    /**
     * POST /api/auth/signup — pendaftaran akun baru dari aplikasi (PUBLIK, tanpa kredensial).
     * Port alur web SignupController@store: DB::transaction, api default, notification
     * default, UserSignupLog + UserActivityLog 'auth:log.signup-successful'.
     * Bedanya: kredensial API (api_key + secret_key) LANGSUNG diisi agar akun siap pakai,
     * dan respons JSON 201 berbentuk konsisten dengan POST /api/auth/login.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $locale = $request->input('user.locale') ?: 'id';

        $validator = validator($request->all(), [
            'user.locale'    => ['required', Rule::exists('locales', 'locale')],
            'user.full_name' => ['required', 'min:8', 'regex:/^[\pL\s\-]+$/u'],
            'user.whatsapp'  => ['required', 'min:5', 'numeric', 'unique:users,whatsapp'],
            'user.email'     => ['required', 'lowercase', 'email:rfc', 'unique:users,email'],
            'user.username'  => ['required', 'lowercase', 'alpha_dash', 'min:3', 'unique:users,username'],
            'user.password'  => ['required', 'min:6'],
        ], [
            'user.locale.required'    => 'Bahasa wajib diisi.',
            'user.locale.exists'      => 'Bahasa tidak valid.',
            'user.full_name.required' => 'Nama lengkap wajib diisi.',
            'user.full_name.min'      => 'Nama lengkap minimal 8 karakter.',
            'user.full_name.regex'    => 'Nama lengkap hanya boleh berisi huruf dan spasi.',
            'user.whatsapp.required'  => 'Nomor WhatsApp wajib diisi.',
            'user.whatsapp.min'       => 'Nomor WhatsApp minimal 5 karakter.',
            'user.whatsapp.numeric'   => 'Nomor WhatsApp hanya boleh berisi angka.',
            'user.whatsapp.unique'    => 'Nomor WhatsApp sudah terdaftar.',
            'user.email.required'     => 'Email wajib diisi.',
            'user.email.lowercase'    => 'Email harus huruf kecil semua.',
            'user.email.email'        => 'Format email tidak valid.',
            'user.email.unique'       => 'Email sudah terdaftar.',
            'user.username.required'  => 'Username wajib diisi.',
            'user.username.lowercase' => 'Username harus huruf kecil semua.',
            'user.username.alpha_dash'=> 'Username hanya boleh berisi huruf, angka, strip, dan underscore.',
            'user.username.min'       => 'Username minimal 3 karakter.',
            'user.username.unique'    => 'Username sudah terdaftar.',
            'user.password.required'  => 'Password wajib diisi.',
            'user.password.min'       => 'Password minimal 6 karakter.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()->all(),
            ], 400, [], JSON_PRETTY_PRINT);
        }

        // Konfirmasi password opsional: bila dikirim dan tidak sama → gagal.
        $password = (string) $request->input('user.password');
        if ($request->filled('user.password_confirmation') && $request->input('user.password_confirmation') !== $password) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => ['Konfirmasi password tidak sama dengan password.'],
            ], 400, [], JSON_PRETTY_PRINT);
        }

        // Kode referral opsional: hanya diterima bila pemilik kode ada (port logika
        // cookie referred_by pada alur web — referensi tidak valid diabaikan).
        $referredBy = null;
        if ($request->filled('referral_code')) {
            $referredBy = User::where('referral_code', (string) $request->input('referral_code'))->value('id');
        }

        $localeModel = Locale::where('locale', $locale)->first();
        $referredById = $referredBy;

        $user = DB::transaction(function () use ($request, $localeModel, $referredById, $password) {
            $user = User::create([
                'user_group_id' => 1,
                'locale_id'     => $localeModel->id,
                'full_name'     => ucwords($request->input('user.full_name')),
                'whatsapp'      => (string) $request->input('user.whatsapp'),
                'email'         => strtolower((string) $request->input('user.email')),
                'username'      => strtolower((string) $request->input('user.username')),
                'password'      => Hash::make($password),
                // Berbeda dari web (secret_key dikosongkan): di API secret_key langsung
                // diisi supaya akun siap dipakai tanpa login ulang.
                'api' => [
                    'api_key'      => generate_api_key(),
                    'secret_key'   => bin2hex(random_bytes(16)),
                    'whitelist_ip' => '',
                ],
                'notification' => [
                    'order'   => ['email' => 0, 'whatsapp' => 0, 'telegram' => 0],
                    'deposit' => ['email' => 0, 'whatsapp' => 0, 'telegram' => 0],
                    'ticket'  => ['email' => 0, 'whatsapp' => 0, 'telegram' => 0],
                ],
                'referred_by' => ($referredById && $referredById !== null) ? $referredById : null,
            ]);

            if ($referredById && $referredById != $user->id) {
                $user->update([
                    'referred_by' => $referredById,
                ]);
            }

            UserSignupLog::create([
                'user_id'     => $user->id,
                'ip_address'  => (string) $request->ip(),
                'user_agent'  => (string) $request->header('User-Agent', ''),
            ]);
            UserActivityLog::create([
                'user_id'        => $user->id,
                'ip_address'     => (string) $request->ip(),
                'user_agent'     => (string) $request->header('User-Agent', ''),
                'content_key'    => 'auth:log.signup-successful',
                'content_params' => (object) [],
            ]);

            return $user;
        });

        $user->refresh();
        $api = $user->api;

        return response()->json([
            'status' => true,
            'data'   => [
                'api_key'    => $api['api_key'],
                'secret_key' => $api['secret_key'],
                'user'       => [
                    'username'  => $user->username,
                    'full_name' => $user->full_name,
                    'email'     => $user->email,
                    'whatsapp'  => $user->whatsapp,
                ],
            ],
        ], 201, [], JSON_PRETTY_PRINT);
    }
}
