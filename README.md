# Indopedia API — Backend Aplikasi

Backend **full-API murni** (tanpa UI) untuk aplikasi mobile Indopedia SMM Panel. Diturunkan dari panel Laravel 12 yang sama dengan situs utama: database, model, service, cron, dan callback payment identik — yang berubah hanya lapisan HTTP-nya.

**Stack:** Laravel 12 · PHP 8.2+ · MySQL

## Karakteristik

- **Stateless JSON**: tidak ada session, tidak ada cookie — semua endpoint memakai `api_key` + `secret_key`.
- **Login anti-bergantung-UI**: `POST /api/auth/login` menerima **username / email / whatsapp + password** (identik akun web) dan mengembalikan kredensial API. Aplikasi tidak pernah scrape halaman web, jadi perubahan UI situs tidak akan pernah merusak aplikasi.
- **Kredensial otomatis**: `api_key` di-generate saat pertama login bila belum ada; `secret_key` yang masih kosong di-generate juga. Login berikutnya mengembalikan nilai yang sama (stabil).
- **Callback & cron utuh**: 5 payment gateway callback dan seluruh cron (order status, sync service, kurs, BCA mutation) tetap berjalan seperti panel asli.

## Endpoint

### Autentikasi & akun
| Endpoint | Keterangan |
|---|---|
| `POST /api/auth/signup` | Pendaftaran akun baru (publik). Body: `user.locale` (default `id`), `user.full_name`, `user.whatsapp`, `user.email`, `user.username`, `user.password` (+ opsional `user.password_confirmation`, `referral_code`). Respons 201 berisi `api_key` + `secret_key` (akun langsung siap pakai). Rate limit 5/menit per IP. |
| `POST /api/auth/login` | Body: `username`, `password` → `data.api_key`, `data.secret_key`, profil ringkas. Rate limit 5 percobaan/5 menit per IP (429 bila kelebihan). |
| `POST /api/auth/forgot-password` | Minta link reset password via email (publik). Body: `email`. Hanya email terverifikasi & akun aktif; 1 permintaan per 30 detik per IP (429 bila kelebihan). |
| `POST /api/auth/reset-password` | Ubah password memakai token dari email (publik). Body: `token`, `new_password`. Token sekali pakai. |
| `POST /api/me` | Body: `api_key`, `secret_key` → profil, saldo, diskon layanan, status. |

> **Catatan reset password:** link pada email reset mengarah ke `APP_FRONTEND_URL`
> (config `services.app.frontend_url`) + `/auth/reset-password?token=...` — form
> reset ditampilkan oleh aplikasi/web utama, bukan oleh backend ini. `APP_FRONTEND_URL`
> **wajib diisi di produksi** (mis. `https://indopedia.id`). Reset hanya berlaku
> untuk **email terverifikasi** dan **akun berstatus aktif**.

### Katalog & order
| Endpoint | Keterangan |
|---|---|
| `POST /api/services` | Daftar layanan (API V1 lama). |
| `POST /api/orders` | Daftar order milik user, filter `status`, paginate. |
| `POST /api/order/create` | Buat order (API V1 lama). |
| `POST /api/order/status` | Status order. |
| `POST /api/order/refill/create` · `POST /api/order/refill/status` | Refill. |
| `POST /api/order/cancel/create` | Cancel. |

### Deposit
| Endpoint | Keterangan |
|---|---|
| `POST /api/payment-methods` | Metode pembayaran aktif (fee, bonus, kode gateway). |
| `POST /api/deposits` | Riwayat deposit, paginate. |
| `POST /api/deposits/create` | Buat deposit (manual & 7 gateway); respons berisi `checkout_url`/`payment_information`. Saldo dikredit otomatis oleh callback. |

### Monitoring (publik)
| Endpoint | Keterangan |
|---|---|
| `POST /api/monitoring` | Order completed (publik, tanpa kredensial). Wajib `page` untuk paginate; filter `filter.service_id`, `filter.search`, urutan `order.column` (id/created_at/process_time) + `order.direction`, `length` (maks 50). |
| `POST /api/monitoring/services` | Daftar layanan yang punya order completed + jumlahnya (publik, maks 100 layanan). |

### Reseller (V2)
| Endpoint | Keterangan |
|---|---|
| `POST /api/v2` | `action`: balance, services, add, status, refill, refill_status. |

### Infrastruktur (non-aplikasi)
- `GET /callback/*` — webhook payment gateway + notif-forwarder.
- `GET /cron/*`, `GET /tasks/*`, `GET /cron/run/{secret}`, `GET /cron/queue/{secret}` — cron HTTP.
- `GET /lang/{locale}/{file}` — terjemahan JSON.
- `GET /up` — health check.

### Bentuk respons
Sukses:
```json
{ "status": true, "data": { } }
```
Gagal:
```json
{ "status": false, "message": "...", "errors": ["..."] }
```

## Instalasi

```bash
composer install
cp .env.example .env        # sesuaikan DB_DATABASE/DB_USERNAME/DB_PASSWORD
php artisan key:generate
php artisan serve           # development
```

Tidak ada `npm install` / `npm run build` — repo ini tanpa aset frontend. `APP_FRONTEND_URL` (opsional) dipakai sebagai `return_url` gateway pembayaran ke aplikasi/web utama.
