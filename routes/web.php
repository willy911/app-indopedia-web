<?php

/*
|--------------------------------------------------------------------------
| Web Routes (Backend API Murni)
|--------------------------------------------------------------------------
| UI (Inertia/React) sudah dihapus. Grup web kini hanya berisi:
| 1. Cronjob via HTTP (prefix /cron, /tasks, /cron/run, /cron/queue) —
|    dipanggil scheduler/hosting yang cron-nya hanya bisa wget/curl.
| 2. Endpoint lang JSON (GET /lang/{locale}/{file}) — membaca file
|    resources/lang langsung, tanpa perlu build aset.
| Halaman guest/blogs/sitemaps/ranking/locale-set/analytics-data/export-translations
| dihapus bersama UI-nya. API utama: routes/api.php (V1) & routes/api-v2.php (V2).
*/

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lang JSON (port admin-smm)
|--------------------------------------------------------------------------
| Membaca file resources/lang/{locale}/{file}.php lalu mengembalikan JSON
| (key diubah ke kebab-case, placeholder :x jadi {x}) untuk frontend di
| domain/subdomain lain. resources/lang TETAP menjadi sumber data.
*/
Route::get('/lang/{locale}/{file}', function (string $locale, string $file) {
    if (!in_array($locale, ['id', 'en', 'ru', 'hi', 'pt', 'tr', 'ar', 'es', 'de', 'it', 'fil'])) {
        abort(404);
    }

    $filePath = resource_path("lang/{$locale}/{$file}.php");

    if (!is_file($filePath)) {
        return response()->json([], 404);
    }

    $raw = require $filePath;
    $convertKeysToKebabCase = function (array $array) use (&$convertKeysToKebabCase) {
        $converted = [];
        foreach ($array as $key => $value) {
            $newKey = \Illuminate\Support\Str::kebab(str_replace('_', '-', $key));
            if (is_array($value)) {
                $converted[$newKey] = $convertKeysToKebabCase($value);
            } else {
                $value = preg_replace('/\:([a-zA-Z_]+)/', '{{$1}}', $value);
                $converted[$newKey] = $value;
            }
        }
        return $converted;
    };

    return response()->json($convertKeysToKebabCase($raw));
})->name('lang.show');

/*
|--------------------------------------------------------------------------
| Cronjob HTTP (port admin-smm, stub config)
|--------------------------------------------------------------------------
| Dipanggil oleh scheduler/hosting via HTTP. Aman tanpa kredensial:
| controller melewati eksekusi + memberi log jelas bila config kosong.
| CATATAN: diblok rule nginx/WAF di VPS, tetap dipertahankan; penggantinya
| di prefix /tasks di atas.
*/
Route::prefix('cron')->name('cron.')->group(function () {
    Route::get('bca-mutation', \App\Http\Controllers\Cronjob\BCAMutationController::class)->name('bca-mutation');
    Route::get('update-mobile-notification', [\App\Http\Controllers\Cronjob\MobileNotificationController::class, 'update'])->name('update-mobile-notification');
    Route::get('check-status', [\App\Http\Controllers\Cronjob\OrderController::class, 'checkStatus'])->name('check-status');
    Route::get('check-status-refill', [\App\Http\Controllers\Cronjob\OrderController::class, 'checkStatusRefill'])->name('check-status-refill');
    Route::get('send-order', [\App\Http\Controllers\Cronjob\OrderController::class, 'sendOrder'])->name('send-order');
    Route::get('sync-services', [\App\Http\Controllers\Cronjob\ServiceController::class, 'syncServices'])->name('sync-services');
    Route::get('sync-services-by-id/{target:id}', [\App\Http\Controllers\Cronjob\ServiceController::class, 'syncServicesByID'])->name('sync-services-by-id');
    Route::get('check-service-status', [\App\Http\Controllers\Cronjob\ServiceController::class, 'checkServiceStatus'])->name('check-service-status');
});

/*
|--------------------------------------------------------------------------
| Tasks via HTTP (pola admin-smm: tanpa secret)
|--------------------------------------------------------------------------
| Pengganti /cron/* yang diblok rule nginx/WAF di VPS. Tanpa secret,
| pengaman: throttle 1x/menit per-IP+path dan atomic lock anti-dobel
| (panggilan tumpuk hanya 1 yang jalan, lainnya 409).
*/

// Rate limiter "tasks": maks 1 hit per menit per kombinasi IP + path.
// Didaftarkan di sini (bukan bootstrap/app.php) karena file route tetap
// dieksekusi tiap request saat route:cache tidak aktif.
RateLimiter::for('tasks', function (Request $request) {
    return Limit::perMinute(1)->by(sha1($request->ip() . '|' . $request->path()));
});

Route::prefix('tasks')->name('tasks.')->middleware('throttle:tasks')->group(function () {
    Route::get('sync-services', [\App\Http\Controllers\Cronjob\ServiceController::class, 'syncServices'])->name('sync-services');
    Route::get('sync-services-by-id/{target:id}', [\App\Http\Controllers\Cronjob\ServiceController::class, 'syncServicesByID'])->name('sync-services-by-id');
    Route::get('check-service-status', [\App\Http\Controllers\Cronjob\ServiceController::class, 'checkServiceStatus'])->name('check-service-status');
});

/*
|--------------------------------------------------------------------------
| Cronjob via HTTP (untuk panel yang cron-nya hanya bisa wget/curl)
|--------------------------------------------------------------------------
| Memicu `schedule:run` lewat HTTP. Dilindungi CRON_SECRET di .env.
| Tanpa secret (kosong) atau secret salah => 404, sama polanya dengan
| endpoint mutasi HP (NOTIF_FORWARDER_TOKEN).
*/
Route::get('cron/run/{secret?}', function (?string $secret = null) {
    $expected = trim((string) config('services.cron.secret', ''));
    if ($expected === '' || !is_string($secret) || $secret === '' || !hash_equals($expected, $secret)) {
        abort(404);
    }
    Artisan::call('schedule:run');
    return response()->json([
        'ok'      => true,
        'message' => 'schedule:run executed',
    ]);
})->where('secret', '[A-Za-z0-9]{10,128}');

/*
|--------------------------------------------------------------------------
| Queue drain via HTTP (pasangan /cron/run untuk setup wget-only)
|--------------------------------------------------------------------------
| Memproses job di queue (mis. hasil service:sync-service) dengan batas
| waktu ketat: berhenti saat queue kosong atau maks 55 detik, jadi aman
| dipanggil cron wget tiap menit. Tanpa secret valid => 404.
*/
Route::get('cron/queue/{secret?}', function (?string $secret = null) {
    $expected = trim((string) config('services.cron.secret', ''));
    if ($expected === '' || !is_string($secret) || $secret === '' || !hash_equals($expected, $secret)) {
        abort(404);
    }
    Artisan::call('queue:work', [
        '--queue'           => 'service-provider-fetch,default',
        '--stop-when-empty' => true,
        '--max-time'        => 55,
        '--tries'           => 3,
        '--sleep'           => 3,
    ]);
    return response()->json([
        'ok'      => true,
        'message' => 'queue drained',
    ]);
})->where('secret', '[A-Za-z0-9]{10,128}');
