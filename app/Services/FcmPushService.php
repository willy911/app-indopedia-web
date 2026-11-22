<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FcmPushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const TOKEN_CACHE_KEY = 'fcm_oauth_token';

    /**
     * Kirim notifikasi DATA (tanpa bunyi/tampil notifikasi) ke topic user_{id}
     * supaya aplikasi Android langsung refresh saldo.
     *
     * @return array{ok: bool, body?: string, error?: string}
     */
    public static function sendBalanceUpdate(int $userId): array
    {
        $path = env('FCM_CREDENTIALS_PATH')
            ?: env('GOOGLE_APPLICATION_CREDENTIALS')
            ?: '/etc/indopedia/firebase-adminsdk.json';

        // Path relatif dari .env diresolve terhadap root aplikasi.
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (strlen($path) >= 3 && $path[1] === ':' && ($path[2] === '/' || $path[2] === '\\'));
        if (!$isAbsolute) {
            $path = base_path($path);
        }

        if (!@is_file($path)) {
            $error = 'FCM saldo gagal: file kredensial tidak ditemukan atau tidak dapat dibaca: ' . $path;
            Log::warning($error);

            return ['ok' => false, 'error' => $error];
        }

        $credentials = json_decode((string) @file_get_contents($path), true);
        if (
            !is_array($credentials)
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])
            || empty($credentials['project_id'])
        ) {
            $error = 'FCM saldo gagal: kredensial Admin SDK tidak valid atau tidak lengkap.';
            Log::warning($error);

            return ['ok' => false, 'error' => $error];
        }

        try {
            $accessToken = self::getAccessToken($credentials);

            $payload = [
                'message' => [
                    'topic'   => 'user_' . $userId,
                    'data'    => ['type' => 'balance_update'],
                    'android' => ['data' => ['type' => 'balance_update']],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", $payload);

            if (!$response->successful()) {
                Log::warning('FCM balance push gagal: ' . $response->body());
            } else {
                Log::info('FCM balance push terkirim', [
                    'user_id' => $userId,
                    'topic'   => 'user_' . $userId,
                    'body'    => substr($response->body(), 0, 500),
                ]);
            }

            return [
                'ok'   => $response->successful(),
                'body' => $response->body(),
            ];
        } catch (Throwable $e) {
            Log::warning('FCM balance push gagal: ' . $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ambil (dan cache) OAuth2 access token untuk Firebase Cloud Messaging v1.
     */
    private static function getAccessToken(array $credentials): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () use ($credentials) {
            $tokenUri = $credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;
            $now = self::trustedNow() - 600; // buffer 10 menit agar iat tidak dianggap di masa depan bila jam lokal maju

            $header = self::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = self::base64UrlEncode((string) json_encode([
                'iss'   => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud'   => $tokenUri,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ]));

            $unsigned = $header . '.' . $claim;
            $signature = '';
            if (!openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Gagal menandatangani JWT FCM dengan private key.');
            }
            $assertion = $unsigned . '.' . self::base64UrlEncode($signature);

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);

            $accessToken = $response->json('access_token');
            if (!$response->successful() || !is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Gagal memperoleh access token FCM: ' . $response->body());
            }

            return $accessToken;
        });
    }

    /**
     * Dapatkan waktu tepercaya dari header Date HTTP Google.
     * Fallback ke waktu lokal bila sumber gagal atau hasilnya tidak wajar.
     */
    private static function trustedNow(): int
    {
        try {
            $response = Http::withOptions(['timeout' => 5])->head('https://www.google.com');
            $dateHeader = $response->header('Date');
            if (is_string($dateHeader) && $dateHeader !== '') {
                $parsed = strtotime($dateHeader);
                if ($parsed !== false && $parsed > 0 && abs($parsed - time()) < 86400) {
                    return (int) $parsed;
                }
            }
        } catch (Throwable $e) {
            // Abaikan; gunakan fallback waktu lokal.
        }

        return time();
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
