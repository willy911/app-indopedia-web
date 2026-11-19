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

        $locale = \App\Models\Locale::find(1);

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
}
