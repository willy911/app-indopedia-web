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
use App\Models\Order\Order;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderListController extends Controller
{
    /**
     * POST /api/orders — daftar order milik user (autentikasi api_key + secret_key).
     * Filter status opsional (disaring whitelist), paginate 15/halaman (maks 50).
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
                'errors'  => ['Account not found.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $allowedStatuses = ['pending', 'processing', 'in_progress', 'completed', 'partial', 'canceled', 'error'];
        $statusInput = $request->input('status');
        $statusFilter = [];
        if (is_string($statusInput) && $statusInput !== '') {
            foreach (explode(',', $statusInput) as $item) {
                $item = trim($item);
                if (in_array($item, $allowedStatuses, true)) {
                    $statusFilter[] = $item;
                }
            }
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage ?: 15, 50));

        $locale = Locale::find(1);

        $orders = Order::with('service:id,name')
            ->where('user_id', $user->id)
            ->when(!empty($statusFilter), fn ($query) => $query->whereIn('status', $statusFilter))
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        $data = collect($orders->items())->map(function (Order $order) use ($locale) {
            return [
                'id'          => $order->id,
                'ref_id'      => $order->id,
                'service_id'  => $order->service_id,
                'service'     => $order->service?->name,
                'target'      => $order->target,
                'status'      => $order->status,
                'price'       => (float) $order->price,
                'price_local' => round((float) $order->price * $locale->usd_rate, (int) $locale->max_decimal_digits),
                'start_count' => (float) $order->start_count,
                'remains'     => (float) $order->remains,
                'created_at'  => $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : null,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => $data,
            'meta'   => [
                'current_page' => $orders->currentPage(),
                'per_page'     => $orders->perPage(),
                'last_page'    => $orders->lastPage(),
                'total'        => $orders->total(),
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/order/detail — detail satu order milik user.
     * `price` dalam USD, `price_local` dalam mata uang lokal (kurs Locale id 1).
     * Riwayat isi ulang (refills) & pembatalan (cancel) ikut disertakan.
     */
    public function detail(Request $request): JsonResponse
    {
        $user = User::whereJsonContains('api->api_key', (string) $request->input('api_key'))
            ->whereJsonContains('api->secret_key', (string) $request->input('secret_key'))
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Account not found.',
                'errors'  => ['Account not found.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $validator = validator($request->all(), [
            'id' => ['required', 'integer'],
        ], [
            'id.required' => 'id pesanan wajib diisi.',
            'id.integer'  => 'id pesanan harus berupa angka.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()->all(),
            ], 400, [], JSON_PRETTY_PRINT);
        }

        $order = Order::with([
            'service:id,name',
            'orderRefills' => fn ($query) => $query->orderBy('id', 'desc'),
            'orderCancel',
        ])
            ->where('user_id', $user->id)
            ->where('id', (int) $request->input('id'))
            ->first();
        if (!$order) {
            return response()->json([
                'status'  => false,
                'message' => 'Pesanan tidak ditemukan.',
                'errors'  => ['Pesanan tidak ditemukan.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $locale = Locale::find(1);
        $priceLocal = $locale
            ? round((float) $order->price * (float) $locale->usd_rate, (int) $locale->max_decimal_digits)
            : null;

        // Ambil nilai mentah agar tidak diubah accessor additionalInput() model;
        // hasil bisa berupa object (JSON) maupun string biasa.
        $additionalInput = $order->getRawOriginal('additional_input');
        if (is_string($additionalInput) && $additionalInput !== '') {
            $decoded = json_decode($additionalInput);
            $additionalInput = json_last_error() === JSON_ERROR_NONE ? $decoded : $additionalInput;
        } elseif ($additionalInput === '') {
            $additionalInput = null;
        }

        $refills = $order->orderRefills->map(fn ($refill) => [
            'id'         => $refill->id,
            'status'     => $refill->status,
            'created_at' => $refill->created_at ? $refill->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $refill->updated_at ? $refill->updated_at->format('Y-m-d H:i:s') : null,
        ])->values();

        $cancel = $order->orderCancel;
        $cancelData = $cancel ? [
            'id'         => $cancel->id,
            'status'     => $cancel->status,
            'created_at' => $cancel->created_at ? $cancel->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $cancel->updated_at ? $cancel->updated_at->format('Y-m-d H:i:s') : null,
        ] : null;

        return response()->json([
            'status' => true,
            'data'   => [
                'id'               => $order->id,
                'service_id'       => $order->service_id,
                'service_name'     => $order->service_name ?: $order->service?->name,
                'target'           => $order->target,
                'quantity'         => (float) $order->quantity,
                'price'            => (float) $order->price,
                'price_local'      => $priceLocal,
                'status'           => $order->status,
                'start_count'      => (float) $order->start_count,
                'remains'          => (float) $order->remains,
                'initial_balance'  => (float) $order->initial_balance,
                'final_balance'    => (float) $order->final_balance,
                'additional_input' => $additionalInput,
                'ordered_via'      => $order->ordered_via,
                'created_at'       => $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : null,
                'updated_at'       => $order->updated_at ? $order->updated_at->format('Y-m-d H:i:s') : null,
                'refills'          => $refills,
                'cancel'           => $cancelData,
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
