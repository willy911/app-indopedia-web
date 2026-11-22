<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use App\Models\User\UserBalanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateController extends Controller
{
    /**
     * POST /api/affiliate — data afiliasi/referral untuk aplikasi Android.
     * Kredensial api_key + secret_key, pola sama seperti MeController.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = User::whereJsonContains('api->api_key', (string) $request->input('api_key'))
            ->whereJsonContains('api->secret_key', (string) $request->input('secret_key'))
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Account not found.',
            ], 404, [], JSON_PRETTY_PRINT);
        }

        // Menu affiliate aktif bila website_config('menu')->user->affiliate->enabled == '1'.
        // Default true bila konfigurasi tidak ada supaya menu tidak hilang karena config kosong.
        $enabledValue = data_get((array) website_config('menu'), 'user.affiliate.enabled', null);
        $enabled = $enabledValue === null ? true : (bool) $enabledValue;

        $referralCode = (string) $user->referral_code;
        if ($referralCode === '') {
            $referralCode = User::generateReferralCode();
            $user->referral_code = $referralCode;
            $user->save();
        }

        $totalEarnings = (float) UserBalanceLog::where('user_id', $user->id)
            ->where('type', 'credit')
            ->where('category', 'commission')
            ->sum('amount');

        return response()->json([
            'status' => true,
            'data'   => [
                'enabled'         => $enabled,
                'referral_code'   => $referralCode,
                'referral_url'    => 'https://indopedia.id/ref/' . $referralCode,
                'commission'      => (float) $user->commission,
                'total_referrals' => $user->referrals()->count(),
                'total_earnings'  => $totalEarnings,
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
