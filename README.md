## Setup Project — Backend Aedo Hotel Syariah

### Prasyarat

Pastikan tools berikut sudah terinstal:

- PHP >= 8.2
- Composer
- MySQL 8
- Node.js (opsional, untuk asset)
- [Laragon](https://laragon.org/) / XAMPP / Herd (sebagai local server)

---

### 1. Clone & Masuk ke Direktori

```bash
git clone <url-repo-backend>
cd backend-aedo-hotel
```

---

### 2. Install Dependensi PHP

```bash
composer install
```

---

### 3. Buat File Environment

```bash
cp .env.example .env
php artisan key:generate
```

Kemudian edit file `.env` sesuai konfigurasi lokal kamu:

```env
APP_NAME="Aedo Hotel Syariah"
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=AedoHotel
DB_USERNAME=root
DB_PASSWORD=

# Sanctum SPA — sesuaikan dengan domain frontend
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173
```

---

### 4. Buat Database

Buat database MySQL dengan nama `AedoHotel` (sesuai `DB_DATABASE` di `.env`):

```sql
CREATE DATABASE AedoHotel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

### 5. Jalankan Migration & Seeder

```bash
php artisan migrate --seed
```

Perintah ini akan:
- Membuat seluruh tabel (22 migration)
- Mengisi data awal: 1 akun admin, 1 baris `settings`, dan data demo kamar

> **Akun Admin default** (dari seeder):
> - Email: `admin@aedohotel.com`
> - Password: `password`

---

### 6. Jalankan Server Lokal

```bash
php artisan serve
```

API akan berjalan di `http://localhost:8000`. Semua endpoint berada di prefix `/api/v1`.

---

### 7. Jalankan Scheduler (Opsional)

Scheduler dibutuhkan untuk menandai pembayaran `pending` yang sudah kedaluwarsa menjadi `expired` dan menjalankan backup database otomatis.

```bash
php artisan schedule:work
```

---

### 8. Jalankan Test

```bash
php artisan test
```

---

### Struktur Akun & Role

| Role | Kemampuan |
|---|---|
| `admin` | Akses penuh: CRUD user, laporan, verifikasi pembayaran, pengaturan |
| `pegawai` | Operasional: reservasi, check-in/out, catat pembayaran, housekeeping |

Akun `is_active = false` tidak bisa login.

---

### Catatan Penting

- **Auth** menggunakan Laravel Sanctum mode SPA (cookie session + CSRF), **bukan** token Bearer.
- Frontend dan backend **wajib** berada di domain induk yang sama agar cookie berfungsi (contoh: `localhost`).
- Jangan pernah commit file `.env` atau API key apa pun ke repositori.
- Semua kalkulasi harga (total booking, invoice) dilakukan di **server**, tidak boleh dipercayakan ke frontend.

