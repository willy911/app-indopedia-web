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
use App\Models\Website\WebsiteNews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NewsController extends Controller
{
    /**
     * POST /api/news — daftar berita publik (tanpa kredensial api_key/secret_key).
     * Body opsional: limit (1..10, default 5). Mengikuti kueri tampilan web:
     * status = '1', is_public = '1', urut sort_number lalu id terbaru.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->input('limit', 5), 10));

        // Cache daftar utuh di bawah kunci website_news, limit diterapkan setelahnya
        // supaya satu kunci cache melayani semua nilai limit yang valid.
        $news = Cache::remember('website_news', now()->addHours(6), function () {
            return WebsiteNews::where('status', '1')
                ->where('is_public', '1')
                ->orderByRaw('ISNULL(sort_number), sort_number ASC, id DESC')
                ->get();
        });

        // Kolom internal (id, status, sort_number, updated_at, deleted_at) sengaja
        // tidak disertakan. `image` dibaca dari kolom banner.
        $data = $news->take($limit)->map(function (WebsiteNews $item) {
            return [
                'title'      => $item->title,
                'slug'       => $item->slug,
                'content'    => $item->content,
                'image'      => $item->banner,
                'created_at' => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => $data,
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * POST /api/news/popup — daftar berita popup untuk aplikasi mobile.
     * Publik, tanpa kredensial api_key/secret_key. Mengambil maksimal 10 berita
     * terbaru yang ditandai is_popup = '1' dan masih aktif (status = '1').
     */
    public function popup(Request $request): JsonResponse
    {
        $news = WebsiteNews::where('status', '1')
            ->where('is_popup', '1')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $data = $news->map(function (WebsiteNews $item) {
            return [
                'id'              => $item->id,
                'title'           => $item->title,
                'slug'            => $item->slug,
                'content'         => $item->content,
                'image'           => $item->banner ? rtrim(config('app.cdn_url'), '/') . '/website/' . $item->banner : null,
                'created_at'      => $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : null,
                'is_public'       => (bool) $item->is_public,
                'only_visible_on' => $item->only_visible_on,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => $data,
        ], 200, [], JSON_PRETTY_PRINT);
    }
}
