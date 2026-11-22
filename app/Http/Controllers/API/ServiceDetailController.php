<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Order\Order;
use App\Models\Service\Service;
use App\Models\User\User;
use Carbon\CarbonInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceDetailController extends Controller
{
    /**
     * POST /api/services/detail — detail satu layanan lengkap dengan ringkasan
     * pesanan dan contoh target (kredensial api_key + secret_key, sama seperti /services).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'id' => ['required', 'integer'],
        ], [
            'id.required' => 'id layanan wajib diisi.',
            'id.integer'  => 'id layanan harus berupa angka.',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors()->all(),
            ], 400, [], JSON_PRETTY_PRINT);
        }

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

        // Layanan bersifat global; orders di-eager-load agar accessor
        // avg_quantity / get_avg_time menghasilkan nilai (pola sama seperti /services).
        $service = Service::with([
            'serviceCategory',
            'orders' => fn ($query) => $query->where('status', 'completed')->latest('id')->take(10),
        ])->where('id', (int) $request->input('id'))->first();
        if (!$service) {
            return response()->json([
                'status'  => false,
                'message' => 'Layanan tidak ditemukan.',
                'errors'  => ['Layanan tidak ditemukan.'],
            ], 404, [], JSON_PRETTY_PRINT);
        }

        $locale = Locale::find(1);
        $price = $locale
            ? round((float) $service->price * (float) $locale->usd_rate, (int) $locale->max_decimal_digits)
            : round((float) $service->price, 2);

        $avgTime = $service->get_avg_time;

        return response()->json([
            'status' => true,
            'data'   => [
                'service'        => [
                    'id'                  => $service->id,
                    'name'                => $service->name,
                    'category'            => $service->serviceCategory?->name,
                    'price'               => $price,
                    'refill'              => (bool) $service->is_refill_support,
                    'cancel'              => (bool) $service->is_cancel_support,
                    'refill_days'         => $service->refill_days,
                    'description'         => $service->description,
                    'avg_quantity'        => floor((float) $service->avg_quantity),
                    'avg_time_in_seconds' => floor((float) $avgTime),
                    'avg_time_in_human'   => $avgTime
                        ? CarbonInterval::seconds($avgTime)->cascade()->forHumans()
                        : __('common.empty.no_data_yet'),
                    'min'                 => $service->min,
                    'max'                 => $service->max,
                ],
                'summary'        => $this->buildSummary((int) $service->id),
                'example_target' => $this->resolveExampleTarget($service),
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Ringkasan pesanan layanan dalam satu query (tanpa N+1).
     * Status dikelompokkan: partial/error/canceled -> failed;
     * processing/in_progress -> processing; pending; completed.
     */
    private function buildSummary(int $serviceId): array
    {
        $rows = Order::where('service_id', $serviceId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->get();

        $counts = [
            'pending'    => 0,
            'processing' => 0,
            'completed'  => 0,
            'failed'     => 0,
        ];

        $totalOrders = 0;
        foreach ($rows as $row) {
            $count = (int) $row->cnt;
            $totalOrders += $count;
            $status = (string) $row->status;

            if ($status === 'pending') {
                $counts['pending'] += $count;
            } elseif (in_array($status, ['processing', 'in_progress'], true)) {
                $counts['processing'] += $count;
            } elseif ($status === 'completed') {
                $counts['completed'] += $count;
            } elseif (in_array($status, ['partial', 'error', 'canceled'], true)) {
                $counts['failed'] += $count;
            }
        }

        $summary = [
            'total' => ['count' => $totalOrders],
        ];
        foreach ($counts as $key => $count) {
            $summary[$key] = [
                'count'      => $count,
                'percentage' => $totalOrders > 0 ? round($count / $totalOrders * 100, 2) : 0,
            ];
        }

        return $summary;
    }

    /**
     * Ambil contoh target dari website_config('order_example_target') yang key-nya
     * cocok dengan kategori/nama layanan (case-insensitive). HTML dibersihkan.
     * Mengembalikan string kosong bila config kosong/tidak ada yang cocok.
     */
    private function resolveExampleTarget(Service $service): string
    {
        $config = website_config('order_example_target');
        if (empty($config)) {
            return '';
        }

        $candidates = [];
        if ($service->serviceCategory && $service->serviceCategory->name) {
            $candidates[] = (string) $service->serviceCategory->name;
        }
        if ($service->name) {
            $candidates[] = (string) $service->name;
        }
        $haystack = implode(' ', $candidates);

        $entries = is_object($config) ? get_object_vars($config) : (is_array($config) ? $config : []);
        foreach ($entries as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $needle = (string) $key;
            if ($needle !== '' && stripos($haystack, $needle) !== false) {
                return trim(strip_tags((string) $value));
            }
        }

        return '';
    }
}
