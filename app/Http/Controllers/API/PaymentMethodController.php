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
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    /**
     * POST /api/payment-methods — daftar metode pembayaran aktif untuk form deposit.
     * Map ke bentuk aman (tanpa membocorkan kolom internal); payment_target hanya
     * relevan untuk metode manual (tanpa payment_gateway).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = \App\Models\User\User::whereJsonContains('api->api_key', (string) $request->input('api_key'))
            ->whereJsonContains('api->secret_key', (string) $request->input('secret_key'))
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Account not found.',
                'errors'  => ['Account not found.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $paymentMethods = PaymentMethod::where('status', '1')
            ->orderByRaw('ISNULL(sort_number), sort_number ASC, id DESC')
            ->get();

        $data = $paymentMethods->map(function (PaymentMethod $method) {
            return [
                'id'                             => $method->id,
                'name'                           => $method->name,
                'type'                           => $method->type,
                'payment_gateway'                => $method->payment_gateway,
                'payment_gateway_payment_code'   => $method->payment_gateway_payment_code,
                'admin_fee_configs'              => $method->admin_fee_configs,
                'bonus_configs'                  => $method->bonus_configs,
                'payment_target'                 => $method->payment_gateway ? null : $method->payment_target,
                'minimum'                        => (float) $method->minimum,
                'maximum'                        => (float) $method->maximum,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => $data,
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
