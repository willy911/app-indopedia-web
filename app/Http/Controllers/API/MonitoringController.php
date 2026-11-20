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
use App\Models\Order\Order;
use App\Models\Service\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    /**
     * POST /api/monitoring — daftar order completed untuk halaman monitoring publik.
     * Port cabang JSON dari Guest\Service\MonitoringController (web): butuh param
     * `page` untuk paginate; tanpa `page` dikembalikan struktur kosong + meta.
     * Filter: filter.service_id (multi, dipisah koma), filter.search, urutan
     * (kolom whitelist: id/created_at/process_time — selain itu fallback created_at).
     */
    public function index(Request $request): JsonResponse
    {
        $countOrders = Order::where('status', 'completed')->count();

        if (!$request->has('page')) {
            return response()->json([
                'status' => true,
                'data'   => [
                    'data' => [],
                    'meta' => [
                        'current_page'   => null,
                        'total'          => $countOrders,
                        'per_page'       => null,
                        'message'        => 'Parameter page wajib diisi untuk menampilkan data.',
                    ],
                ],
            ], 200, [], JSON_PRETTY_PRINT);
        }

        // Whitelist kolom & arah urutan agar orderBy tidak bisa menyuntik kolom arbitrer.
        $orderColumn = (string) $request->input('order.column', 'created_at');
        if (!in_array($orderColumn, ['id', 'created_at', 'process_time'], true)) {
            $orderColumn = 'created_at';
        }
        $orderDirection = strtolower((string) $request->input('order.direction', 'desc'));
        if (!in_array($orderDirection, ['asc', 'desc'], true)) {
            $orderDirection = 'desc';
        }

        $length = (int) $request->input('length', 10);
        if ($length < 1) {
            $length = 10;
        }
        $length = min($length, 50);

        // process_time dihitung dari selisih created_at/updated_at (accessor
        // Order::processTime). Pengurutan berdasarkan process_time dipetakan
        // ke updated_at agar setara secara semantik.
        $orderSortColumn = $orderColumn === 'process_time' ? 'updated_at' : $orderColumn;

        $applyFilters = function ($query) use ($request, $countOrders) {
            $query->where('status', 'completed');
            if ($countOrders > 10000) {
                $query->where('created_at', '>=', now()->startOfDay());
                $query->where('created_at', '<=', now()->addDay()->startOfDay());
            }
            if ($request->filled('filter.service_id')) {
                $query->whereIn('service_id', explode(',', $request->input('filter.service_id')));
            }
            if ($request->filled('filter.search')) {
                $query->whereAny([
                    'service_id',
                    'service_name',
                    'target',
                ], 'LIKE', '%' . ($request->input('filter.search')) . '%');
            }
        };

        $tableItems = Order::has('service')->with([
            'service',
        ])->when($request->input(), $applyFilters)
            ->orderBy($orderSortColumn, $orderDirection)
            ->paginate($length)->onEachSide(1);

        $tableItems->getCollection()->transform(function ($item) {
            $item->id           = $item->service_id;
            $item->process_time = $item->processTime;
            return $item;
        });

        return response()->json([
            'status' => true,
            'data'   => $tableItems,
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/monitoring/services — daftar layanan yang punya order completed
     * beserta jumlahnya (dropdown filter monitoring di aplikasi). Port bagian
     * daftar services dari Guest\Service\MonitoringController (web).
     */
    public function services(Request $request): JsonResponse
    {
        $countOrders = Order::where('status', 'completed')->count();

        $services = Service::whereHas('orders', function ($query) use ($countOrders) {
            $query->where('status', 'completed');
            if ($countOrders > 10000) {
                $query->where('created_at', '>=', now()->startOfDay());
                $query->where('created_at', '<=', now()->addDay()->startOfDay());
            }
        })->withCount([
            'orders' => function ($query) use ($countOrders) {
                $query->where('status', 'completed');
                if ($countOrders > 10000) {
                    $query->where('created_at', '>=', now()->startOfDay());
                    $query->where('created_at', '<=', now()->addDay()->startOfDay());
                }
            },
        ])->latest('id')->limit(100)->get()->map(function ($row) {
            return [
                'value' => $row->id,
                'label' => $row->name,
                'count' => $row->orders_count,
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => $services,
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
