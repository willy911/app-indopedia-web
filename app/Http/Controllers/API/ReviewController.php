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
use App\Models\Order\OrderReview;
use App\Models\User\User;
use App\Models\User\UserActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ReviewController extends Controller
{
    /**
     * Cari user dari kredensial api_key + secret_key (pola V1, sama seperti
     * controller API lainnya). Middleware API sudah memvalidasi kredensial,
     * tetapi controller tetap perlu object user untuk pembatasan ownership.
     */
    private function resolveUser(Request $request): ?User
    {
        return User::whereJsonContains('api->api_key', (string) $request->input('api_key'))
            ->whereJsonContains('api->secret_key', (string) $request->input('secret_key'))
            ->first();
    }

    /**
     * Respons gagal seragam: {status:false, message, errors}.
     */
    private function fail(string $message, array $errors, int $code): JsonResponse
    {
        return response()->json([
            'status'  => false,
            'message' => $message,
            'errors'  => $errors,
        ], $code, [], JSON_PRETTY_PRINT);
    }

    private function accountNotFound(): JsonResponse
    {
        return $this->fail('Account not found.', ['Account not found.'], 404);
    }

    private function formatDate($value): ?string
    {
        return $value ? $value->format('Y-m-d H:i:s') : null;
    }

    /**
     * POST /api/reviews — buat ulasan untuk order completed milik sendiri.
     * Satu user hanya bisa mengulas satu layanan sekali; ulasan baru menunggu
     * persetujuan admin (status='0').
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return $this->accountNotFound();
        }

        $validator = validator($request->all(), [
            'order_id' => ['required', 'integer'],
            'rating'   => ['required', 'numeric', 'min:1', 'max:5'],
            'message'  => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return $this->fail('Validasi gagal.', $validator->errors()->all(), 400);
        }

        $order = Order::where('id', (int) $request->input('order_id'))
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->first();
        if (!$order) {
            return $this->fail(
                'Pesanan tidak ditemukan atau belum selesai.',
                ['Pesanan tidak ditemukan atau belum selesai.'],
                404
            );
        }

        $alreadyReviewed = OrderReview::where('user_id', $user->id)
            ->where('service_id', $order->service_id)
            ->first();
        if ($alreadyReviewed) {
            return $this->fail('Layanan ini sudah Anda ulas.', ['Layanan ini sudah Anda ulas.'], 422);
        }

        $throttleKey = 'order-review-create:' . $user->id;
        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->fail(
                'Terlalu banyak percobaan. Coba lagi dalam ' . $seconds . ' detik.',
                ['Coba lagi dalam ' . $seconds . ' detik.'],
                429
            );
        }
        RateLimiter::increment($throttleKey);

        $review = OrderReview::create([
            'user_id'       => $user->id,
            'order_id'      => $order->id,
            'service_id'    => $order->service_id,
            'rating'        => $request->input('rating'),
            'message'       => $request->filled('message') ? $request->input('message') : null,
            'status'        => '0',
            'is_read_admin' => '0',
        ]);

        UserActivityLog::create([
            'user_id'        => $user->id,
            'ip_address'     => (string) $request->ip(),
            'user_agent'     => (string) $request->header('User-Agent', ''),
            'content_key'    => 'orders:log.review_successful',
            'content_params' => (object) ['order_id' => $order->id],
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Ulasan berhasil dikirim. Menunggu persetujuan admin.',
            'data'    => [
                'id'         => $review->id,
                'order_id'   => $review->order_id,
                'service_id' => $review->service_id,
                'rating'     => (float) $review->rating,
                'message'    => $review->message,
                'status'     => $review->status,
                'created_at' => $this->formatDate($review->created_at),
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/reviews/mine — daftar seluruh ulasan milik user (termasuk yang
     * masih menunggu approval), maksimal 50 terbaru.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return $this->accountNotFound();
        }

        $reviews = OrderReview::with('service:id,name')
            ->where('user_id', $user->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->map(function (OrderReview $review) {
                return [
                    'id'           => $review->id,
                    'order_id'     => $review->order_id,
                    'service_id'   => $review->service_id,
                    'service_name' => $review->service?->name,
                    'rating'       => (float) $review->rating,
                    'message'      => $review->message,
                    'status'       => $review->status,
                    'created_at'   => $this->formatDate($review->created_at),
                ];
            })->values();

        return response()->json([
            'status' => true,
            'data'   => $reviews,
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/services/reviews — ringkasan ulasan APPROVED sebuah layanan
     * untuk katalog. Hanya status='1' yang tidak soft-deleted; maksimal 10
     * ulasan terbaru dikembalikan.
     */
    public function serviceReviews(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return $this->accountNotFound();
        }

        $validator = validator($request->all(), [
            'service_id' => ['required', 'integer'],
        ]);
        if ($validator->fails()) {
            return $this->fail('Validasi gagal.', $validator->errors()->all(), 400);
        }

        $serviceId = (int) $request->input('service_id');

        $baseQuery = OrderReview::where('service_id', $serviceId)
            ->where('status', '1')
            ->whereNull('deleted_at');

        $total = (clone $baseQuery)->count();
        $avgRating = round((float) ((clone $baseQuery)->avg('rating') ?? 0), 2);

        $reviews = (clone $baseQuery)
            ->with('user:id,username')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function (OrderReview $review) {
                $username = (string) ($review->user?->username ?? '');
                if (strlen($username) > 3) {
                    $username = substr($username, 0, 3) . 'xxx';
                }

                return [
                    'rating'     => (float) $review->rating,
                    'message'    => $review->message,
                    'username'   => $username,
                    'created_at' => $this->formatDate($review->created_at),
                ];
            })->values();

        return response()->json([
            'status' => true,
            'data'   => [
                'avg_rating' => $avgRating,
                'total'      => $total,
                'reviews'    => $reviews,
            ],
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/reviews/eligible — order completed milik user yang layanannya
     * belum pernah diulas, maksimal 50 terbaru.
     */
    public function eligible(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return $this->accountNotFound();
        }

        $orders = Order::with('service:id,name')
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereDoesntHave('orderReviews')
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->map(function (Order $order) {
                return [
                    'order_id'     => $order->id,
                    'service_id'   => $order->service_id,
                    'service_name' => $order->service?->name,
                    // Tidak ada kolom completed_at; updated_at adalah penanda
                    // terakhir kali status order berubah menjadi completed.
                    'completed_at' => $this->formatDate($order->updated_at),
                ];
            })->values();

        return response()->json([
            'status' => true,
            'data'   => $orders,
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
