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

use App\Models\Deposit\Deposit;
use App\Models\Order\Order;
use App\Models\Service\Service;
use App\Models\User\User;
use Carbon\CarbonInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Locale;

class ServiceController extends Controller
{

    public function __invoke(Request $request)
    {
        $checkUser = $user = User::whereJsonContains('api->api_key', $request->input('api_key'))->whereJsonContains('api->secret_key', $request->input('secret_key'))->first();
        $localeId = Locale::find(1);
        $services = Service::has('serviceCategory')->with(['serviceCategory', 'serviceFavorites' => function ($query) use ($user) {
            $query->where('user_id', $user->id);
        }, 'orders' => function ($query) {
            $query->where('status', 'completed')->latest('id')->take(10);
        }])->when($request->input(), function ($query) use ($request, $user) {
            if ($request->input('is_favorites')) {
                $query->whereHas('serviceFavorites', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                });
            }
        })->get()->map(function ($row) use ($localeId) {
            return [
                'id' => $row->id,
                'type' => $row->type,
                'category' => $row->serviceCategory->name,
                'name' => $row->name,
                'price' => round(
                    (float) $row->price * $localeId->usd_rate,
                    (int) $localeId->max_decimal_digits
                ),
                'min' => $row->min,
                'max' => $row->max,
                'refill' => (bool) $row->is_refill_support,
                'cancel' => (bool) $row->is_cancel_support,
                'is_best_seller' => (bool) $row->is_best_seller,
                'refill_days' => $row->refill_days,
                'description' => $row->description,
                'avg_quantity' => floor($row->avg_quantity),
                'avg_time_in_seconds' => floor($row->get_avg_time),
                'avg_time_in_human' => $row->get_avg_time ? CarbonInterval::seconds($row->get_avg_time)->cascade()->forHumans() : __('common.empty.no_data_yet'),
                'created_at' => $row->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $row->updated_at->format('Y-m-d H:i:s'),
            ];
        });
        return response()->json([
            'status' => true,
            'data'   => $services,
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Best seller berdasarkan pesanan riil bulan ini (bukan flag manual is_best_seller).
     *
     * Status yang dihitung sebagai pesanan berhasil/berjalan:
     * completed, processing, in_progress, partial.
     * pending, canceled, dan error sengaja dikecualikan.
     */
    public function bestSellers(): JsonResponse
    {
        // leftJoin dipakai agar pesanan dengan layanan yang sudah dihapus tetap
        // terhitung; service_name bersifat opsional (null bila tidak ditemukan).
        // Satu query saja, tidak ada N+1.
        $rows = Order::query()
            ->selectRaw('orders.service_id, services.name as service_name, COUNT(*) as orders_count')
            ->leftJoin('services', 'services.id', '=', 'orders.service_id')
            ->whereBetween('orders.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->whereIn('orders.status', ['completed', 'processing', 'in_progress', 'partial'])
            ->groupBy('orders.service_id', 'services.name')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get();

        $data = $rows->map(function ($row) {
            return [
                'service_id'   => (int) $row->service_id,
                'orders_count' => (int) $row->orders_count,
                'service_name' => $row->service_name,
            ];
        })->values()->all();

        return response()->json([
            'status' => true,
            'data'   => $data,
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
