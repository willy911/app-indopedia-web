<?php

use App\Http\Middleware\API;
use App\Http\Middleware\APIV2;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Bootstrap API murni
|--------------------------------------------------------------------------
| Repo ini sekarang BACKEND FULL-API (tanpa UI Inertia/React):
| - web   : hanya cron via HTTP + endpoint lang JSON (lihat routes/web.php).
| - api   : API V1 (middleware App\Http\Middleware\API).
| - api-v2: API V2 (V2Controller, middleware APIV2).
| - callback: jalur deposit (5 payment gateway + notif_forwarder).
| Group ajax/admin/user dan redirect UI dihapus bersama folder UI-nya.
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api-v2')->prefix('api')->group(base_path('routes/api-v2.php'));

            // Callback payment gateway (5 gateway + notif_forwarder) = jalur deposit,
            // WAJIB tetap hidup. Group ajax/admin/user yang ikut dihapus tidak
            // dimuat lagi. CSRF dikecualikan untuk /callback/* (lihat withMiddleware).
            Route::middleware('web')->prefix('callback')->name('callback.')->group(base_path('routes/callback.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Situs berjalan di belakang reverse proxy (Cloudflare -> aaPanel/Nginx).
        // Tanpa ini Laravel menganggap koneksi http sehingga URL yang
        // di-generate bisa http:// (mixed-content) dan skema tidak terdeteksi.
        $middleware->trustProxies(at: '*');

        // API V1: middleware auth API di-append ke grup "api" (routes/api.php).
        $middleware->api(append: [
            API::class,
        ]);

        // API V2: grup khusus dengan middleware APIV2 (routes/api-v2.php).
        $middleware->group('api-v2', [
            APIV2::class,
        ]);

        // Callback payment gateway & notif_forwarder dikirim oleh pihak ketiga
        // tanpa CSRF token, jadi grup web dikecualikan untuk path /callback/*.
        $middleware->validateCsrfTokens(except: [
            '/callback/*',
        ]);

        // Alias user.account.verify dulu dipakai route UI (user.php / web.php lama)
        // yang sudah dihapus; middleware-nya tidak lagi direferensikan siapa pun.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Semua response error dari route api (V1 prefix api/ + group api-v2)
        // dipaksa JSON {status:false, message} tanpa membocorkan detail internal.
        // Callback yang dipanggil lewat middleware web (prefix /callback/*) tetap
        // mengandalkan return JSON eksplisit di routes/callback.php.
        $exceptions->respond(function (Response $response, Throwable $exception, $request) {
            if (!($request instanceof \Illuminate\Http\Request) || !($request->is('api/*') || $request->routeIs('api*'))) {
                return $response;
            }

            // ValidationException: Laravel sudah punya JSON 422 standar — pertahankan.
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                return $response;
            }

            // AuthenticationException: pastikan 401 JSON, bukan redirect login.
            if ($exception instanceof AuthenticationException) {
                return new JsonResponse([
                    'status'  => false,
                    'message' => 'Unauthenticated.',
                ], 401, [], JSON_PRETTY_PRINT);
            }

            $statusCode = $response->getStatusCode();

            // Jangan ganggu response non-error (2xx/3xx).
            if ($statusCode < 400) {
                return $response;
            }

            $messages = [
                400 => 'Bad Request.',
                401 => 'Unauthenticated.',
                403 => 'Forbidden.',
                404 => 'Not Found.',
                419 => 'Page Expired.',
                422 => 'Unprocessable Entity.',
                429 => 'Too Many Requests.',
                500 => 'Server Error.',
                503 => 'Service Unavailable.',
            ];

            // Di luar production, jelaskan error apa yang terjadi biar gampang debug.
            $message = $messages[$statusCode] ?? 'Server Error.';
            if (!app()->environment('production')) {
                $message = $exception->getMessage() ?: $message;
            }

            return new JsonResponse([
                'status'  => false,
                'message' => $message,
            ], $statusCode, [], JSON_PRETTY_PRINT);
        });

        // Halaman error inertia/back()->with dihapus — production cukup JSON sederhana
        // dari respond() di atas.
    })->create();
