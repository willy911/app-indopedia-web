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
use App\Models\PaymentMethod;
use App\Models\User\User;
use App\Models\User\UserActivityLog;
use App\Services\User\DepositService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DepositController extends Controller
{
    /**
     * Cari user dari kredensial api_key + secret_key (pola V1).
     */
    private function resolveUser(Request $request): ?User
    {
        return User::whereJsonContains('api->api_key', (string) $request->input('api_key'))
            ->whereJsonContains('api->secret_key', (string) $request->input('secret_key'))
            ->first();
    }

    /**
     * POST /api/deposits — daftar deposit milik user (terbaru dulu, paginate).
     * Angka uang TIDAK dikonversi (sudah base USD di DB); kurs_rate disertakan
     * agar aplikasi bisa menampilkan sendiri dalam mata uang lokal.
     */
    public function list(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Account not found.',
                'errors'  => ['Account not found.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage ?: 15, 50));
        $page = max(1, (int) $request->input('page', 1));

        $locale = Locale::find(1);

        $deposits = Deposit::where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = collect($deposits->items())->map(function (Deposit $deposit) use ($locale) {
            return [
                'id'                           => $deposit->id,
                'payment_gateway_payment_id'   => $deposit->payment_gateway_payment_id,
                'payment_method_name'          => $deposit->payment_method_name,
                'status'                       => $deposit->status,
                'amount'                       => (float) $deposit->amount,
                'admin_fee'                    => (float) $deposit->admin_fee,
                'bonus'                        => (float) $deposit->bonus,
                'unique_code'                  => (float) $deposit->unique_code,
                'total_payment'                => (float) $deposit->total_payment,
                'total_balance'                => (float) $deposit->total_balance,
                'kurs_rate'                    => $locale ? (float) ($locale->usd_rate_buy > 0 ? $locale->usd_rate_buy : $locale->usd_rate) : null,
                'created_at'                   => $deposit->created_at ? $deposit->created_at->format('Y-m-d H:i:s') : null,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => $data,
            'meta'   => [
                'current_page' => $deposits->currentPage(),
                'per_page'     => $deposits->perPage(),
                'last_page'    => $deposits->lastPage(),
                'total'        => $deposits->total(),
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/deposits/create — buat deposit (port dari web User\DepositController::store).
     * Perbedaan dari web: stateless (kredensial API), error = JSON 422/400 Indonesia,
     * return_url gateway = frontend URL (callback server-to-server tetap lewat /callback/*).
     * Saldo TIDAK dikredit di sini — dikredit oleh callback gateway setelah bayar.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Account not found.',
                'errors'  => ['Account not found.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $validator = validator($request->all(), [
            'payment_method.id'    => ['required', 'integer', 'exists:payment_methods,id'],
            'deposit.amount'       => ['required', 'numeric', 'min:1'],
            'deposit.phone_number' => ['nullable', 'string', 'max:30'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()->all(),
            ], 400, [], JSON_PRETTY_PRINT);
        }

        $checkDepositLimit = Deposit::where('status', 'pending')
            ->where('user_id', $user->id)
            ->count();
        if ($checkDepositLimit >= 1) {
            return response()->json([
                'status'  => false,
                'message' => 'Masih ada deposit yang belum dibayar.',
                'errors'  => ['Selesaikan atau batalkan deposit pending terlebih dahulu.'],
            ], 422, [], JSON_PRETTY_PRINT);
        }

        $localeId = Locale::find(1);
        $kursBeli = (float) ($localeId->usd_rate_buy > 0 ? $localeId->usd_rate_buy : $localeId->usd_rate);
        $frontendUrl = config('services.app.frontend_url') ?: config('app.url');

        $credentials = [
            'user_id'           => $user->id,
            'payment_method_id' => (int) $request->input('payment_method.id'),
            'amount'            => $request->input('deposit.amount'),
            'bonus'             => 0,
            'unique_code'       => 0,
            'kurs_rate'         => $kursBeli,
            'ip_address'        => $request->ip(),
            'user_agent'        => (string) $request->header('User-Agent', ''),
            'initial_balance'   => $user->balance,
            'final_balance'     => $user->balance,
        ];
        $paymentMethod = PaymentMethod::find($credentials['payment_method_id']);
        $credentials['payment_method_name'] = $paymentMethod->name;
        if ($paymentMethod->admin_fee_configs->type === 'fixed') {
            $credentials['admin_fee'] = $paymentMethod->admin_fee_configs->value;
        } else {
            $credentials['admin_fee'] = ($paymentMethod->admin_fee_configs->value / 100) * $credentials['amount'];
        }
        $bonusConfigs = $paymentMethod->bonus_configs ?? [];
        $amount = (float) $credentials['amount'];
        $bonusPercent = 0;
        if (!empty($bonusConfigs)) {
            $matchedBonus = null;
            $highestBonus = 0;
            foreach ($bonusConfigs as $config) {
                $min = (float) $config->min;
                $max = (float) $config->max;
                $bonus = (float) $config->bonus;
                if ($bonus > $highestBonus) {
                    $highestBonus = $bonus;
                }
                if ($amount >= $min && $amount <= $max) {
                    $matchedBonus = $bonus;
                    break;
                }
            }
            $maxValueInConfigs = max(array_column($bonusConfigs, 'max'));
            if ($amount > (float) $maxValueInConfigs && !$matchedBonus) {
                $matchedBonus = $highestBonus;
            }
            $bonusPercent = $matchedBonus ?? 0;
        }
        $credentials['bonus'] = ($bonusPercent / 100) * $amount;
        if (!$paymentMethod->payment_gateway) {
            $credentials['is_manual'] = '1';
            $credentials['unique_code'] = generate_unique_code();
            $credentials['payment_information'] = $paymentMethod->payment_target;
            $credentials['sender'] = $request->input('deposit.phone_number');
            $credentials['total_payment'] = $amount + $credentials['admin_fee'] + $credentials['unique_code'];
            $credentials['total_balance'] = $amount + $credentials['bonus'] + $credentials['unique_code'];
        } else {
            $credentials['payment_gateway_payment_id'] = generate_deposit_payment_gateway_payment_id();
            $credentials['total_payment'] = $amount + $credentials['admin_fee'] + $credentials['unique_code'];
            $credentials['total_balance'] = $amount + $credentials['bonus'] + $credentials['unique_code'];
        }

        $paymentUnavailable = fn (): JsonResponse => response()->json([
            'status'  => false,
            'message' => 'Metode pembayaran sedang tidak tersedia.',
            'errors'  => ['Silakan gunakan metode pembayaran lainnya.'],
        ], 422, [], JSON_PRETTY_PRINT);

        if ($paymentMethod->payment_gateway === 'paydisini') {
            $paymentGatewayCredentials = [
                'key'           => website_config('payment_gateway')->paydisini->api_key,
                'request'       => 'new',
                'unique_code'   => $credentials['payment_gateway_payment_id'],
                'service'       => $paymentMethod->payment_gateway_payment_code,
                'amount'        => intval($credentials['total_payment'] * $kursBeli),
                'note'          => $paymentMethod->name,
                'valid_time'    => '10800',
                'type_fee'      => '1',
                'payment_guide' => true,
                'signature'     => md5(website_config('payment_gateway')->paydisini->api_key . $credentials['payment_gateway_payment_id'] . $paymentMethod->payment_gateway_payment_code . intval($credentials['total_payment'] * $kursBeli) . '10800' . 'NewTransaction'),
                'return_url'    => $frontendUrl,
            ];
            if ($paymentMethod->type === 'e_wallet') {
                $paymentGatewayCredentials['ewallet_phone'] = $request->input('deposit.phone_number');
            }
            $curl = Http::asForm()->post('https://api.paydisini.co.id/v1/', $paymentGatewayCredentials);
            if (!$curl->json()['success']) {
                return $paymentUnavailable();
            }
            $credentials['payment_gateway_payment_token'] = $curl->json()['data']['pay_id'];
            $credentials['payment_gateway_checkout_url'] = $curl->json()['data']['checkout_url'];
            $credentials['payment_gateway_api_log'] = $curl->json();
        }
        if ($paymentMethod->payment_gateway === 'tripay') {
            if (in_array($paymentMethod->payment_gateway_payment_code, ['QRIS', 'QRISC', 'QRIS2'])) {
                $credentials['admin_fee'] = (($paymentMethod->admin_fee_configs->value / 100) * $credentials['amount']) + (750 / $kursBeli);
                $credentials['total_payment'] = $amount + $credentials['admin_fee'] + $credentials['unique_code'];
            }
            $paymentGatewayCredentials = [
                'method'         => $paymentMethod->payment_gateway_payment_code,
                'merchant_ref'   => $credentials['payment_gateway_payment_id'],
                'amount'         => intval($credentials['total_payment'] * $kursBeli),
                'customer_name'  => $user->full_name,
                'customer_email' => $user->email,
                'customer_phone' => $paymentMethod->type === 'e_wallet' ? $request->input('deposit.phone_number') : $user->whatsapp,
                'return_url'     => $frontendUrl,
                'order_items'    => [[
                    'sku'      => 'Deposit',
                    'name'     => $paymentMethod->name,
                    'price'    => intval($credentials['total_payment'] * $kursBeli),
                    'quantity' => 1,
                ]],
                'expired_time'   => (time() + (3 * 60 * 60)),
                'signature'      => hash_hmac('sha256', website_config('payment_gateway')->tripay->merchant_id . $credentials['payment_gateway_payment_id'] . intval($credentials['total_payment'] * $kursBeli), website_config('payment_gateway')->tripay->private_key),
            ];
            $curl = Http::asForm()->withHeaders([
                'Authorization' => 'Bearer ' . website_config('payment_gateway')->tripay->api_key,
            ])->post('https://tripay.co.id/api/transaction/create', $paymentGatewayCredentials);
            if (!$curl->json()['success']) {
                return $paymentUnavailable();
            }
            $credentials['payment_gateway_payment_token'] = $curl->json()['data']['reference'];
            $credentials['payment_gateway_checkout_url'] = $curl->json()['data']['checkout_url'];
            $credentials['payment_gateway_api_log'] = $curl->json();
        }
        if ($paymentMethod->payment_gateway === 'tokopay') {
            if (in_array($paymentMethod->payment_gateway_payment_code, ['QRIS_CUSTOM'])) {
                $credentials['admin_fee'] = (($paymentMethod->admin_fee_configs->value / 100) * $credentials['amount']) + (250 / $kursBeli);
                $credentials['total_payment'] = $amount + $credentials['admin_fee'] + $credentials['unique_code'];
            }
            $paymentGatewayCredentials = [
                'kode_channel'   => $paymentMethod->payment_gateway_payment_code,
                'reff_id'        => $credentials['payment_gateway_payment_id'],
                'amount'         => intval($credentials['total_payment'] * $kursBeli),
                'merchant_id'    => website_config('payment_gateway')->tokopay->merchant_id,
                'customer_name'  => $user->full_name,
                'customer_email' => $user->email,
                'customer_phone' => $paymentMethod->type === 'e_wallet' ? $request->input('deposit.phone_number') : $user->whatsapp,
                'expired_ts'     => (time() + (3 * 60 * 60)),
                'signature'      => md5(website_config('payment_gateway')->tokopay->merchant_id . ':' . website_config('payment_gateway')->tokopay->secret_key . ':' . $credentials['payment_gateway_payment_id']),
            ];
            $curl = Http::get('https://api.tokopay.id/v1/order?merchant=' . website_config('payment_gateway')->tokopay->merchant_id . '&secret=' . website_config('payment_gateway')->tokopay->secret_key . '&ref_id=' . $credentials['payment_gateway_payment_id'] . '&nominal=' . intval($credentials['total_payment'] * $kursBeli) . '&metode=' . $paymentMethod->payment_gateway_payment_code . '');
            if (!$curl->json()['status']) {
                return $paymentUnavailable();
            }
            $credentials['payment_gateway_payment_token'] = $curl->json()['data']['trx_id'];
            $credentials['payment_gateway_checkout_url'] = $curl->json()['data']['pay_url'];
            $credentials['payment_gateway_api_log'] = $curl->json();
        }
        if ($paymentMethod->payment_gateway === 'duitku') {
            $duitkuConfig = new \Duitku\Config(
                website_config('payment_gateway')->duitku->api_key ?? '',
                website_config('payment_gateway')->duitku->merchant_id ?? ''
            );
            $duitkuConfig->setSandboxMode((bool) (website_config('payment_gateway')->duitku->sandbox ?? false));
            $duitkuConfig->setSanitizedMode(false);
            $duitkuConfig->setDuitkuLogs(false);
            $paymentAmount      = $credentials['amount'] * $kursBeli;
            $paymentMethodCode  = $paymentMethod->payment_gateway_payment_code;
            $email              = $user->email;
            $phoneNumber        = $user->whatsapp;
            $productDetails     = $credentials['payment_gateway_payment_id'];
            $merchantOrderId    = time();
            $additionalParam    = '';
            $merchantUserInfo   = '';
            $customerVaName     = $user->full_name;
            $callbackUrl        = url('/callback/duitku');
            $returnUrl          = $frontendUrl;
            $expiryPeriod       = 60;

            $params = array(
                'paymentAmount'    => $paymentAmount,
                'paymentMethod'    => $paymentMethodCode,
                'merchantOrderId'  => $merchantOrderId,
                'productDetails'   => $productDetails,
                'additionalParam'  => $additionalParam,
                'merchantUserInfo' => $merchantUserInfo,
                'customerVaName'   => $customerVaName,
                'email'            => $email,
                'phoneNumber'      => $phoneNumber,
                'callbackUrl'      => $callbackUrl,
                'returnUrl'        => $returnUrl,
                'expiryPeriod'     => $expiryPeriod
            );

            try {
                $responseDuitkuApi = \Duitku\Api::createInvoice($params, $duitkuConfig);
                $json = json_decode($responseDuitkuApi);
                $credentials['payment_gateway_payment_token'] = $json->reference;
                $credentials['payment_gateway_checkout_url'] = $json->paymentUrl;
                $credentials['payment_gateway_api_log'] = $json;
            } catch (Exception $e) {
                info($e->getMessage());
                return $paymentUnavailable();
            }
        }
        if ($paymentMethod->payment_gateway === 'midtrans') {
            \Midtrans\Config::$serverKey = website_config('payment_gateway')->midtrans->server_key;
            \Midtrans\Config::$isProduction = !((bool) (website_config('payment_gateway')->midtrans->sandbox ?? false));
            \Midtrans\Config::$isSanitized = true;
            \Midtrans\Config::$is3ds = true;
            \Midtrans\Config::$overrideNotifUrl = url('callback/midtrans');
            $paymentGatewayCredentials = array(
                'transaction_details' => array(
                    'order_id'     => $credentials['payment_gateway_payment_id'],
                    'gross_amount' => intval($credentials['total_payment'] * $kursBeli),
                ),
                'customer_details'    => array(
                    'first_name' => $user->full_name,
                    'last_name'  => null,
                    'email'      => $user->email,
                    'phone'      => $user->whatsapp,
                )
            );
            try {
                $curl = \Midtrans\Snap::createTransaction($paymentGatewayCredentials);
                $credentials['payment_gateway_payment_token'] = $curl->token;
                $credentials['payment_gateway_checkout_url'] = $curl->redirect_url;
                $credentials['payment_gateway_api_log'] = $curl;
            } catch (Exception $e) {
                info($e->getMessage());
                return $paymentUnavailable();
            }
        }
        if ($paymentMethod->payment_gateway === 'cryptomus') {
            $paymentKey = website_config('payment_gateway')->cryptomus->payment_key;
            $merchantUuid = website_config('payment_gateway')->cryptomus->merchant_uuid;
            $payment = \Cryptomus\Api\Client::payment($paymentKey, $merchantUuid);
            $data = [
                'amount'              => (string) $credentials['total_payment'],
                'currency'            => 'USD',
                'order_id'            => $credentials['payment_gateway_payment_id'],
                'url_return'          => $frontendUrl,
                'url_callback'        => url('/callback/cryptomus'),
                'is_payment_multiple' => false,
                'lifetime'            => '7200',
            ];
            try {
                $curl = $payment->create($data);
                $credentials['payment_gateway_payment_token'] = $curl['uuid'];
                $credentials['payment_gateway_checkout_url'] = $curl['url'];
                $credentials['payment_gateway_api_log'] = $curl;
            } catch (Exception $e) {
                info($credentials);
                info($e->getMessage());
                return $paymentUnavailable();
            }
        }
        if ($paymentMethod->payment_gateway === 'flazpay') {
            $flazpayCredentials = website_config('payment_gateway')->flazpay ?? null;
            if (!$flazpayCredentials || $flazpayCredentials->merchant_id == '' || $flazpayCredentials->client_id == '' || $flazpayCredentials->client_secret == '') {
                return $paymentUnavailable();
            }
            // Flazpay menerima nominal dalam Rupiah (min. 100) — konsisten dengan
            // gateway lain yang mengirim `total_payment * usd_rate`. Nilai dasar
            // USD tetap yang disimpan ke DB.
            $flazpayAmount = intval(round($credentials['total_payment'] * $kursBeli));
            $paymentGatewayCredentials = [
                'mid'           => $flazpayCredentials->merchant_id,
                'client_id'     => $flazpayCredentials->client_id,
                'client_secret' => $flazpayCredentials->client_secret,
                'action'        => 'new',
                'unique_code'   => $credentials['payment_gateway_payment_id'],
                'service'       => $paymentMethod->payment_gateway_payment_code,
                'amount'        => $flazpayAmount,
                'note'          => $paymentMethod->name,
                'type_fee'      => 1,
                'phone_number'  => $paymentMethod->type === 'e_wallet' ? $request->input('deposit.phone_number') : $user->whatsapp,
                'return_url'    => $frontendUrl,
                // Signature wajib memakai `amount` yang sama dengan yang dikirim.
                'signature'     => md5($flazpayCredentials->client_id . $flazpayCredentials->client_secret . $flazpayCredentials->merchant_id . $credentials['payment_gateway_payment_id'] . $paymentMethod->payment_gateway_payment_code . $flazpayAmount . 'new')
            ];
            $curl = Http::asForm()->post('https://flazpay.id/snap/v1/request/create', $paymentGatewayCredentials);
            $response = $curl->json();
            if (!is_array($response) || !isset($response['status']) || !$response['status']) {
                info($response);
                return $paymentUnavailable();
            }
            $credentials['payment_gateway_payment_token'] = $response['data']['unique_code'];
            $credentials['payment_gateway_checkout_url'] = $response['data']['redirect_url'];
            $credentials['payment_gateway_api_log'] = $response;
        }

        $insertDeposit = Deposit::create($credentials);
        // Refresh agar default DB (mis. status 'pending') ikut terbaca dalam respons.
        $insertDeposit->refresh();
        DepositService::invalidateUserDepositsCache($user->id);
        UserActivityLog::create([
            'user_id'        => $user->id,
            'ip_address'     => (string) $request->ip(),
            'user_agent'     => (string) $request->header('User-Agent', ''),
            'content_key'    => 'deposits:log.create-successful',
            'content_params' => (object) ['deposit_id' => $insertDeposit->id],
        ]);

        return response()->json([
            'status' => true,
            'data'   => [
                'id'                             => $insertDeposit->id,
                'status'                         => $insertDeposit->status,
                'payment_gateway'                => $paymentMethod->payment_gateway,
                'payment_gateway_payment_id'     => $insertDeposit->payment_gateway_payment_id,
                'payment_gateway_payment_token'  => $insertDeposit->payment_gateway_payment_token,
                'checkout_url'                   => $insertDeposit->payment_gateway_checkout_url,
                'payment_information'            => $insertDeposit->payment_information,
                'total_payment'                  => round((float) $insertDeposit->total_payment, 8),
                'total_balance'                  => round((float) $insertDeposit->total_balance, 8),
                'unique_code'                    => round((float) $insertDeposit->unique_code, 8),
                'amount'                         => round((float) $insertDeposit->amount, 8),
                'admin_fee'                      => round((float) $insertDeposit->admin_fee, 8),
                'bonus'                          => round((float) $insertDeposit->bonus, 8),
                'kurs_rate'                      => (float) $insertDeposit->kurs_rate,
                'created_at'                     => $insertDeposit->created_at ? $insertDeposit->created_at->format('Y-m-d H:i:s') : null,
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
