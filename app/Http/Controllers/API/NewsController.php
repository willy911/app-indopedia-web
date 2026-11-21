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
}
