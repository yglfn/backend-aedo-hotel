<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoomStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\HousekeepingTask;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Harness pengujian blackbox backend Aedo Hotel untuk dokumen uji (tabel:
 * ID, Nama, Skenario, Deskripsi, Langkah Uji, Data Uji, Hasil yang Diharapkan,
 * Hasil Aktual, Status, Catatan).
 *
 * Aturan main harness ini:
 * - Tidak mengubah kode produksi. Hanya memanggil endpoint + memeriksa database.
 * - "Hasil Aktual" selalu berisi response/jejak database yang sungguh-sungguh terjadi,
 *   bukan asumsi. Status Pass/Fail dihitung dari cocok/tidaknya dengan harapan.
 * - Semua kasus dijalankan pada DB terisolasi (phpunit.xml: aedo_hotel_testing)
 *   agar deterministik dan tidak pernah menyentuh database kerja.
 * - Hasil dirangkum ke ../.qa/qa_results.json untuk dikonversi ke Excel/Word.
 */
class QaBackendAuditTest extends TestCase
{
    use RefreshDatabase;

    private const OUT_FILE = 'C:/laragon/www/aedo-hotel/.qa/qa_results.json';

    private array $rows = [];

    private int $seq = 0;

    private int $roomSeq = 0;

    private User $admin;

    private User $pegawai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed');

        $this->admin   = User::where('role', UserRole::Admin)->first();
        $this->pegawai = User::where('role', UserRole::Pegawai)->first();
    }

    public function test_qa_backend_audit_suite(): void
    {
        $this->authCases();
        $this->rbacCases();
        $this->settingsCases();
        $this->masterDataCases();
        $this->customerCases();
        $this->bookingCases();
        $this->checkInOutCases();
        $this->shiftPaymentCases();
        $this->invoiceReportCases();
        $this->housekeepingMaintenanceCases();
        $this->miscCases();

        @mkdir(dirname(self::OUT_FILE), 0777, true);
        file_put_contents(self::OUT_FILE, json_encode([
            'generated_at' => now()->toIso8601String(),
            'phpunit_env'  => [
                'db'      => config('database.connections.' . config('database.default') . '.database'),
            ],
            'rows' => $this->rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $fails = array_values(array_filter($this->rows, fn ($r) => $r['status'] !== 'Pass'));

        $this->assertNotEmpty($this->rows, 'Harness harus menghasilkan kasus uji.');

        fwrite(STDERR, sprintf("\n[QA] %d kasus dijalankan, %d tidak sesuai harapan.\n", count($this->rows), count($fails)));
        foreach ($fails as $f) {
            fwrite(STDERR, sprintf("[QA] %s %s => %s\n", $f['id'], $f['nama'], $f['actual']));
        }
    }

    // =====================================================================
    // Kerangka kerja harness
    // =====================================================================

    /**
     * $fn mengembalikan [hasilAktual, cocok?, catatan?].
     */
    private function case(string $group, string $nama, string $deskripsi, string $langkah, string $data, string $expected, callable $fn): void
    {
        $this->seq++;

        try {
            $result                 = $fn();
            $actual                 = (string) ($result[0] ?? '');
            $ok                     = (bool) ($result[1] ?? false);
            $note                   = (string) ($result[2] ?? '');
        } catch (\Throwable $e) {
            $actual = get_class($e) . ': ' . $e->getMessage();
            $ok     = false;
            $note   = 'Harness menangkap exception, jadi hasil tidak bisa dibandingkan.';
        }

        $this->rows[] = [
            'id'       => sprintf('BE-%03d', $this->seq),
            'nama'     => $nama,
            'skenario' => $group,
            'deskripsi' => $deskripsi,
            'langkah'  => $langkah,
            'data'     => $data,
            'expected' => $expected,
            'actual'   => (string) $actual,
            'status'   => $ok ? 'Pass' : 'Fail',
            'catatan'  => $note ?: '',
        ];
    }

    private function req(string $method, string $url, ?User $as = null, array $payload = [], array $headers = [])
    {
        $test = $as ? $this->actingAs($as) : $this;

        if ($headers) {
            $test = $test->withHeaders($headers);
        }

        return match (strtoupper($method)) {
            'GET'    => $test->getJson($url),
            'POST'   => $test->postJson($url, $payload),
            'PUT'    => $test->putJson($url, $payload),
            'PATCH'  => $test->patchJson($url, $payload),
            'DELETE' => $test->deleteJson($url, $payload),
            default  => throw new \InvalidArgumentException("Method {$method} tidak dikenal"),
        };
    }

    /** Ringkasan response yang layak dibaca di sel Excel: "HTTP 409 - pesan". */
    private function describe($resp): string
    {
        $status = $resp->getStatusCode();
        $json   = $resp->json();
        $brief  = '';

        if (is_array($json)) {
            foreach (['error', 'message', 'status'] as $key) {
                if (isset($json[$key]) && is_string($json[$key])) {
                    $brief = $json[$key];
                    break;
                }
            }
            if ($brief === '' && isset($json['errors']) && is_array($json['errors'])) {
                $first = reset($json['errors']);
                $field = (string) key($json['errors']);
                $brief = $field . ': ' . (is_array($first) ? implode('; ', $first) : (string) $first);
            }
            if ($brief === '' && array_key_exists('data', $json)) {
                $data = $json['data'];
                if (is_array($data)) {
                    if (isset($data['data'], $data['total']) && is_array($data['data'])) {
                        $brief = 'pagination: ' . count($data['data']) . ' baris dari total ' . $data['total']
                            . ' (per_page ' . ($data['per_page'] ?? '-') . ')';
                    } elseif (isset($data['id'])) {
                        $brief = 'data.id=' . $data['id'];
                    } elseif (isset($data[0])) {
                        $brief = 'data berisi ' . count($data) . ' baris';
                    } else {
                        $brief = 'data dengan kunci ' . implode(',', array_slice(array_keys($data), 0, 6));
                    }
                } else {
                    $brief = 'data terisi';
                }
            }
        }

        $brief = preg_replace('/\s+/', ' ', $brief);

        return 'HTTP ' . $status . ($brief !== '' ? ' - ' . $brief : '');
    }

    /** Booking siap pakai + data master pendukungnya (nomor kamar unik tiap panggilan). */
    private function mkBooking(int $nights = 2, float $price = 500000, ?User $as = null): array
    {
        $n        = ++$this->roomSeq;
        $customer = Customer::create(['name' => "Tamu QA {$n}", 'phone' => '0812000' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
        $roomType = RoomType::create(['name' => "QA Tipe {$n}", 'price' => $price, 'max_person' => 2]);
        $room     = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'QA' . $n, 'floor' => 1]);

        $resp = $this->req('POST', '/api/v1/bookings', $as ?? $this->admin, [
            'customer_id' => $customer->id,
            'source'      => 'walk_in',
            'check_in'    => now()->format('Y-m-d'),
            'check_out'   => now()->addDays($nights)->format('Y-m-d'),
            'guest_count' => 1,
            'rooms'       => [$room->id],
            'guests'      => [['name' => "Tamu QA {$n}", 'ktp' => '32000000000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'is_primary' => true]],
        ]);

        return [
            'response' => $resp,
            'booking'  => Booking::find($resp->json('data.id')),
            'room'     => $room,
            'roomType' => $roomType,
            'customer' => $customer,
        ];
    }

    private function invoiceTotal(Booking $booking): float
    {
        $booking->refresh();

        return (float) ($booking->invoice?->total ?? 0);
    }

    private function money(float $value): string
    {
        return 'Rp ' . number_format($value, 0, ',', '.');
    }

    /** Nilai enum/scalar yang aman digabung ke teks laporan. */
    private function strv($value): string
    {
        if ($value instanceof \UnitEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : (is_array($value) ? json_encode($value) : '-');
    }

    /** Bukti bayar bisa tersimpan di disk local maupun public. */
    private function proofOnDisk(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return Storage::disk('local')->exists($path) || Storage::disk('public')->exists($path);
    }

    // =====================================================================
    // 1. Autentikasi
    // =====================================================================

    private function authCases(): void
    {
        $g = 'Autentikasi';

        $this->case($g, 'Login admin valid', 'Kredensial admin yang benar menghasilkan sesi + token API.',
            '1) POST /api/v1/auth/login', 'email=admin@aedohotel.com, password=password123',
            'HTTP 200, body memuat message "Login berhasil", user.role=admin, dan token',
            function () {
                $resp = $this->postJson('/api/v1/auth/login', ['email' => 'admin@aedohotel.com', 'password' => 'password123']);
                $ok   = $resp->getStatusCode() === 200
                    && $resp->json('user.role') === 'admin'
                    && is_string($resp->json('token')) && strlen((string) $resp->json('token')) > 20;

                return [$this->describe($resp) . ' | token=' . ($resp->json('token') ? 'diterbitkan' : 'KOSONG')
                    . ' | last_login_at=' . ($resp->json('user.last_login_at') ? 'terisi' : 'KOSONG'), $ok,
                    'Catatan login juga disimpan pada users.last_login_at (AGENTS.md butir 10).'];
            });

        $this->case($g, 'Login password salah', 'Password keliru ditolak tanpa membocorkan field internal.',
            '1) POST /api/v1/auth/login', 'email=admin@aedohotel.com, password=salah123',
            'HTTP 422 dengan pesan "Email atau password salah." pada field email',
            function () {
                $resp = $this->postJson('/api/v1/auth/login', ['email' => 'admin@aedohotel.com', 'password' => 'salah123']);
                $ok   = $resp->getStatusCode() === 422
                    && str_contains((string) json_encode($resp->json('errors') ?? []), 'Email atau password salah');

                return [$this->describe($resp), $ok];
            });

        $this->case($g, 'Login akun nonaktif', 'Akun is_active=false tidak boleh masuk sistem.',
            '1) Buat akun nonaktif 2) POST /api/v1/auth/login', 'email=nonaktif@qa.test, password=password123, is_active=false',
            'HTTP 422 dengan pesan akun tidak aktif',
            function () {
                User::create([
                    'name' => 'Akun Nonaktif QA', 'email' => 'nonaktif@qa.test',
                    'password' => bcrypt('password123'), 'role' => UserRole::Pegawai, 'is_active' => false,
                ]);
                $resp = $this->postJson('/api/v1/auth/login', ['email' => 'nonaktif@qa.test', 'password' => 'password123']);
                $ok   = $resp->getStatusCode() === 422 && str_contains((string) json_encode($resp->json()), 'tidak aktif');

                return [$this->describe($resp), $ok];
            });

        $this->case($g, 'Endpoint terlindungi tanpa token', 'Request tanpa autentikasi tidak boleh membaca data.',
            '1) GET /api/v1/bookings tanpa header auth', 'tanpa token/cookie',
            'HTTP 401 dan body tidak memuat data reservasi',
            function () {
                $resp = $this->getJson('/api/v1/bookings');
                return [$this->describe($resp), $resp->getStatusCode() === 401];
            });

        $this->case($g, 'GET /auth/me memakai bearer token', 'Token hasil login bisa dipakai untuk profil.',
            '1) POST /auth/login 2) GET /auth/me dengan Authorization: Bearer', 'token dari langkah 1',
            'HTTP 200 dengan data.id milik user tersebut',
            function () {
                $login = $this->postJson('/api/v1/auth/login', ['email' => 'pegawai@aedohotel.com', 'password' => 'password123']);
                $token = (string) $login->json('token');
                $resp  = $this->withToken($token)->getJson('/api/v1/auth/me');
                $ok    = $resp->getStatusCode() === 200 && (int) $resp->json('data.id') === (int) $this->pegawai->id;

                return [$this->describe($resp), $ok, 'Dipakai juga oleh Postman collection (jalur non-SPA).'];
            });

        $this->case($g, 'Logout mencabut bearer token', 'Setelah logout, token lama tidak boleh dipakai lagi.',
            '1) login 2) POST /auth/logout 3) GET /auth/me dengan token lama', 'token api-token',
            'Langkah 3 HTTP 401 (token dihapus dari personal_access_tokens)',
            function () {
                $login = $this->postJson('/api/v1/auth/login', ['email' => 'pegawai@aedohotel.com', 'password' => 'password123']);
                $token = (string) $login->json('token');
                $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(200);

                // Bersihkan guard yang masih menempel di container supaya request
                // berikutnya benar-benar hanya mengandalkan bearer token.
                $this->app['auth']->forgetGuards();

                $after  = $this->withToken($token)->getJson('/api/v1/auth/me');
                $remain = DB::table('personal_access_tokens')->where('name', 'api-token')->where('tokenable_id', $this->pegawai->id)->count();

                return [$this->describe($after) . ' | sisa token user itu di DB=' . $remain, $after->getStatusCode() === 401];
            });

        $this->case($g, 'Rate limiting login', 'Titik login dibatasi agar tidak bisa di-brute force.',
            '1) POST /auth/login dengan password salah 11x berturut-turut', 'email=admin@aedohotel.com (password salah)',
            'Permintaan ke-11 HTTP 429 (throttle:10,1 sesuai AGENTS.md bagian 9)',
            function () {
                $codes = [];
                for ($i = 0; $i < 11; $i++) {
                    $codes[] = $this->postJson('/api/v1/auth/login', ['email' => 'admin@aedohotel.com', 'password' => 'nonsense' . $i])->getStatusCode();
                }
                // Batasi efek samping: limiter dibersihkan supaya kasus lain tidak kena 429
                Cache::clear();

                return ['kode status 1..11 = ' . implode(',', $codes), in_array(429, $codes, true),
                    'Cache per testsuite memakai driver array, jadi limiter dibersihkan setelah kasus ini.'];
            });
    }

    // =====================================================================
    // 2. Hak akses per role
    // =====================================================================

    private function rbacCases(): void
    {
        $g = 'Hak Akses (RBAC)';

        $blocked = [
            ['PUT', '/api/v1/settings', ['hotel_name' => 'X', 'tax_percentage' => 5], 'Ubah pengaturan sistem'],
            ['POST', '/api/v1/users', ['name' => 'Klon QA', 'email' => 'klon@qa.test', 'password' => 'password123', 'role' => 'pegawai'], 'Tambah pegawai'],
            ['GET', '/api/v1/users', [], 'Daftar pegawai'],
            ['POST', '/api/v1/room-types', ['name' => 'Tipe Ilegal', 'price' => 1, 'max_person' => 1], 'Tambah tipe kamar'],
            ['POST', '/api/v1/services', ['name' => 'Layanan Ilegal', 'price' => 10000, 'unit' => 'x'], 'Tambah layanan'],
            ['GET', '/api/v1/reports/revenue', [], 'Laporan pendapatan'],
        ];

        foreach ($blocked as $i => [$method, $url, $payload, $label]) {
            $this->case($g, 'Pegawai ditolak: ' . $label, 'Hanya admin yang boleh melakukan ' . strtolower($label) . '.',
                '1) login sebagai pegawai 2) ' . $method . ' ' . $url, 'role=pegawai',
                'HTTP 403 (Fortify/role middleware), bukan sekadar menu disembunyikan',
                function () use ($method, $url, $payload) {
                    $resp = $this->req($method, $url, $this->pegawai, is_array($payload) ? $payload : []);

                    return [$this->describe($resp), $resp->getStatusCode() === 403];
                });
        }

        $this->case($g, 'Pegawai boleh operasional kamar', 'Pegawai boleh melihat kamar/layanan dan mengubah status kamar.',
            '1) GET /rooms 2) GET /services 3) PATCH /rooms/{id}/status', 'role=pegawai, status=cleaning',
            'Ketiga request HTTP 200 (AGENTS.md bagian 3)',
            function () {
                $room = Room::first();
                $a    = $this->req('GET', '/api/v1/rooms', $this->pegawai);
                $b    = $this->req('GET', '/api/v1/services', $this->pegawai);
                $c    = $this->req('PATCH', "/api/v1/rooms/{$room->id}/status", $this->pegawai, ['status' => 'cleaning']);

                return [
                    'GET /rooms=' . $a->getStatusCode() . ', GET /services=' . $b->getStatusCode() . ', PATCH status=' . $c->getStatusCode(),
                    $a->getStatusCode() === 200 && $b->getStatusCode() === 200 && $c->getStatusCode() === 200,
                ];
            });

        $this->case($g, 'Pegawai boleh mencatat & admin yang memverifikasi', 'Verifikasi bukti bayar adalah kewenangan admin.',
            '1) pegawai catat transfer 2) pegawai PATCH /payments/{id}/verify 3) admin verify', 'amount=100.000, method=transfer',
            'Langkah 2 HTTP 403, langkah 3 HTTP 200 + status verified',
            function () {
                $this->req('POST', '/api/v1/shifts/start', $this->pegawai);
                $b  = $this->mkBooking()['booking'];
                $p1 = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->pegawai, [
                    'amount' => 100000, 'method' => 'transfer', 'type' => 'dp',
                ]);
                $pid    = $p1->json('data.id');
                $byPeg  = $this->req('PATCH', "/api/v1/payments/{$pid}/verify", $this->pegawai);
                $byAdm  = $this->req('PATCH', "/api/v1/payments/{$pid}/verify", $this->admin);

                return [
                    'catat=' . $p1->getStatusCode() . ' (payment id=' . $pid . '), verify pegawai=' . $this->describe($byPeg) . ', verify admin=' . $this->describe($byAdm),
                    $p1->getStatusCode() === 201 && $byPeg->getStatusCode() === 403 && $byAdm->getStatusCode() === 200
                        && $byAdm->json('data.status') === PaymentStatus::Verified->value,
                ];
            });

        $this->case($g, 'Akun nonaktif kehilangan akses sesi aktif', 'Nonaktifkan akun harus langsung mencabut akses.',
            '1) pegawai login 2) admin PATCH /users/{id}/deactivate 3) GET /auth/me dengan token lama', 'token pegawai',
            'Langkah 3 HTTP 401 (middleware active)',
            function () {
                $staff = User::create([
                    'name' => 'Pegawai Cabut QA', 'email' => 'cabut@qa.test', 'password' => bcrypt('password123'),
                    'role' => UserRole::Pegawai, 'is_active' => true,
                ]);
                $token = (string) $this->postJson('/api/v1/auth/login', ['email' => 'cabut@qa.test', 'password' => 'password123'])->json('token');
                $this->req('PATCH', "/api/v1/users/{$staff->id}/deactivate", $this->admin);
                $this->app['auth']->forgetGuards();
                $after = $this->withToken($token)->getJson('/api/v1/auth/me');

                return [$this->describe($after), $after->getStatusCode() === 401];
            });
    }

    // =====================================================================
    // 3. Pengaturan sistem
    // =====================================================================

    private function settingsCases(): void
    {
        $g = 'Pengaturan Sistem';

        $this->case($g, 'GET settings bersifat publik', 'Branding hotel dibutuhkan layar login sebelum autentikasi.',
            '1) GET /api/v1/settings tanpa login', 'tanpa token',
            'HTTP 200 dengan hotel_name & tax_percentage, tanpa field kredensial',
            function () {
                $resp = $this->getJson('/api/v1/settings');
                $data = (array) $resp->json('data');
                $leak = array_filter(
                    array_keys($data),
                    fn (string $key) => str_contains($key, 'secret')
                        || str_contains($key, 'key')
                        || str_contains($key, 'password')
                        || str_contains($key, 'token')
                );

                return [$this->describe($resp) . ' | field=' . implode(',', array_slice(array_keys($data), 0, 8))
                    . ' | field sensitif=' . (count($leak) ? implode(',', $leak) : 'tidak ada'),
                    $resp->getStatusCode() === 200 && isset($data['hotel_name'], $data['tax_percentage']) && count($leak) === 0];
            });

        $this->case($g, 'PUT settings mengubah pajak & jam operasional', 'Perubahan berlaku untuk tagihan berikutnya.',
            '1) PUT /api/v1/settings 2) cek database 3) cek audit_logs', 'tax_percentage=12',
            'HTTP 200, settings.tax_percentage=12, audit log settings.updated tercatat',
            function () {
                $resp = $this->req('PUT', '/api/v1/settings', $this->admin, [
                    'hotel_name' => 'Aedo Hotel Syariah', 'hotel_address' => 'Jl. Uji No. 1', 'hotel_phone' => '021-555',
                    'default_check_in' => '14:00:00', 'default_check_out' => '12:00:00',
                    'tax_percentage' => 12,
                ]);
                $row   = Setting::first();
                $audit = AuditLog::where('action', 'settings.updated')->count();

                return [$this->describe($resp) . ' | db tax=' . $row->tax_percentage . ', audit=' . $audit,
                    $resp->getStatusCode() === 200 && (float) $row->tax_percentage === 12.0 && $audit > 0];
            });

        $this->case($g, 'Validasi settings menolak nilai mustahil', 'Pajak atau ambang DP di luar rentang wajar harus ditolak server.',
            '1) PUT /api/v1/settings dengan tax=-5 & min_dp=150', 'tax_percentage=-5, min_dp_percentage=150',
            'HTTP 422 dengan error pada field terkait, data lama tidak berubah',
            function () {
                $before = Setting::first()->tax_percentage;
                $resp   = $this->req('PUT', '/api/v1/settings', $this->admin, [
                    'hotel_name' => 'Aedo Hotel Syariah', 'default_check_in' => '14:00:00', 'default_check_out' => '12:00:00',
                    'tax_percentage' => -5, 'min_dp_percentage' => 150,
                ]);

                return [$this->describe($resp) . ' | tax lama tetap=' . $before,
                    $resp->getStatusCode() === 422 && (float) Setting::first()->tax_percentage === (float) $before];
            });

        $this->case($g, 'Field kredensial tidak bisa ditulis lewat settings', 'Endpoint settings hanya menerima field bisnis yang dikenal.',
            '1) PUT settings dengan api_key 2) GET /settings 3) cek kolom settings di raw DB', 'api_key=SECRET-QA-123',
            'HTTP 200 tapi tidak ada kolom/nilai yang memuat secret',
            function () {
                $put = $this->req('PUT', '/api/v1/settings', $this->admin, [
                    'hotel_name' => 'Aedo Hotel Syariah', 'default_check_in' => '14:00:00', 'default_check_out' => '12:00:00',
                    'tax_percentage' => 11,
                    'api_key' => 'SECRET-QA-123',
                ]);
                $get  = $this->getJson('/api/v1/settings');
                $cols = DB::table('settings')->get()->map(fn ($row) => get_object_vars($row))->first();
                $dirty = array_filter(
                    (array) $cols,
                    fn ($value) => is_string($value) && str_contains($value, 'SECRET-QA-123')
                );

                return ['PUT=' . $put->getStatusCode() . ', kolom=' . implode(',', array_keys((array) $cols))
                    . ', kolom memuat secret=' . (count($dirty) ? implode(',', array_keys($dirty)) : 'tidak ada'),
                    $put->getStatusCode() === 200 && count($dirty) === 0 && ($get->json('data.api_key') ?? null) === null];
            });

        Setting::first()->update(['tax_percentage' => 11, 'min_dp_percentage' => 0]);
    }

    // =====================================================================
    // 4. Data master: tipe kamar, kamar, layanan
    // =====================================================================

    private function masterDataCases(): void
    {
        $g = 'Tipe Kamar';

        $this->case($g, 'Admin membuat tipe kamar', 'Master harga per malam untuk kamar.',
            '1) POST /api/v1/room-types', 'name=QA Suite, price=750000, max_person=3',
            'HTTP 201 dengan id baru',
            function () {
                $resp = $this->req('POST', '/api/v1/room-types', $this->admin, [
                    'name' => 'QA Suite', 'price' => 750000, 'max_person' => 3, 'facilities' => 'AC, WiFi',
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 201 && (int) $resp->json('data.id') > 0];
            });

        $this->case($g, 'Nama tipe kamar harus unik', 'Duplikasi nama merusak acuan harga.',
            '1) POST tipe kamar baru 2) POST nama sama', 'name=QA Suite (dua-duanya)',
            'Request kedua HTTP 422 dengan pesan unik',
            function () {
                $resp = $this->req('POST', '/api/v1/room-types', $this->admin, ['name' => 'QA Suite', 'price' => 100000, 'max_person' => 1]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Ubah harga tercatat di audit', 'Perubahan harga adalah aksi penting (AGENTS.md butir 10).',
            '1) PUT /room-types/{id} 2) cek audit_logs', 'price: 750000 -> 800000',
            'HTTP 200 + audit log perubahan harga',
            function () {
                $type = RoomType::where('name', 'QA Suite')->first();
                $resp = $this->req('PUT', "/api/v1/room-types/{$type->id}", $this->admin, ['name' => 'QA Suite', 'price' => 800000, 'max_person' => 3]);
                $log  = AuditLog::where('auditable_type', RoomType::class)->where('auditable_id', $type->id)->count();

                return [$this->describe($resp) . ' | audit log tipe kamar=' . $log,
                    $resp->getStatusCode() === 200 && (float) $type->fresh()->price === 800000.0 && $log > 0];
            });

        $this->case($g, 'Tipe kamar terpakai tidak bisa dihapus', 'Histori transaksi tidak boleh yatim.',
            '1) DELETE /room-types/{id} yang masih dipakai kamar', 'tipe Standard (punya kamar)',
            'HTTP 422/409 dan baris tetap ada (soft delete hanya bila tak terpakai)',
            function () {
                $type = RoomType::whereHas('rooms')->first();
                $resp = $this->req('DELETE', "/api/v1/room-types/{$type->id}", $this->admin);

                return [$this->describe($resp) . ' | masih ada di DB=' . (RoomType::withTrashed()->find($type->id) ? 'ya' : 'TIDAK'),
                    in_array($resp->getStatusCode(), [409, 422], true) && RoomType::withTrashed()->find($type->id)?->deleted_at === null];
            });

        $g = 'Kamar';

        $this->case($g, 'Admin membuat kamar', 'Kamar fisik terhubung ke tipe + lantai.',
            '1) POST /api/v1/rooms', 'room_type_id=QA Suite, room_number=QA900, floor=9',
            'HTTP 201, status default available',
            function () {
                $type = RoomType::where('name', 'QA Suite')->first();
                $resp = $this->req('POST', '/api/v1/rooms', $this->admin, [
                    'room_type_id' => $type->id, 'room_number' => 'QA900', 'floor' => 9, 'status' => 'available',
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 201 && $resp->json('data.status') === 'available'];
            });

        $this->case($g, 'Nomor kamar harus unik', 'Nomor adalah identitas operasional kamar.',
            '1) POST kamar 2) POST nomor sama', 'room_number=QA900 (dua-duanya)',
            'Request kedua HTTP 422',
            function () {
                $type = RoomType::where('name', 'QA Suite')->first();
                $resp = $this->req('POST', '/api/v1/rooms', $this->admin, ['room_type_id' => $type->id, 'room_number' => 'QA900', 'floor' => 9]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Ubah status kamar menulis riwayat', 'Setiap perubahan status wajib ada di room_status_logs.',
            '1) PATCH /rooms/{id}/status 2) cek room_status_logs', 'status=maintenance, reason=AC bocor',
            'HTTP 200 + 1 baris log with from/to/changed_by',
            function () {
                $room = Room::where('room_number', 'QA900')->first();
                $from = $room->status;
                $resp = $this->req('PATCH', "/api/v1/rooms/{$room->id}/status", $this->admin, ['status' => 'maintenance', 'reason' => 'AC bocor']);
                $log  = RoomStatusLog::where('room_id', $room->id)->latest('id')->first();

                return [$this->describe($resp) . ' | log: ' . ($log ? $this->strv($log->from_status) . ' -> ' . $this->strv($log->to_status) . ' oleh user #' . $log->changed_by : 'TIDAK ADA'),
                    $resp->getStatusCode() === 200 && $log && $this->strv($log->to_status) === 'maintenance' && (int) $log->changed_by === (int) $this->admin->id && $this->strv($from) !== 'maintenance'];
            });

        $this->case($g, 'Status kamar menolak nilai di luar enum', 'Input harus dibatasi pada 5 status.',
            '1) PATCH /rooms/{id}/status', 'status=terbakar',
            'HTTP 422 dan status kamar tidak berubah',
            function () {
                $room = Room::where('room_number', 'QA900')->first();
                $resp = $this->req('PATCH', "/api/v1/rooms/{$room->id}/status", $this->admin, ['status' => 'terbakar']);

                return [$this->describe($resp) . ' | status DB=' . $room->fresh()->status->value,
                    $resp->getStatusCode() === 422 && $room->fresh()->status->value === 'maintenance'];
            });

        $this->case($g, 'Kamar maintenance tidak dijual', 'Kamar rusak tidak boleh muncul di daftar tersedia.',
            '1) GET /rooms/availability?check_in&check_out', 'QA900 sedang maintenance',
            'HTTP 200 tanpa kamar QA900 pada respons',
            function () {
                $resp  = $this->req('GET', '/api/v1/rooms/availability?check_in=' . now()->addDays(5)->format('Y-m-d') . '&check_out=' . now()->addDays(6)->format('Y-m-d'), $this->admin);
                $found = collect($resp->json('data') ?? [])->firstWhere('room_number', 'QA900');

                return [$this->describe($resp) . ' | QA900 ada di daftar=' . ($found ? 'YA (buruk)' : 'tidak'), $resp->getStatusCode() === 200 && $found === null];
            });

        $this->case($g, 'Availability menolak tanggal lampau', 'check_in tidak boleh sebelum hari ini.',
            '1) GET /rooms/availability dengan check_in kemarin', 'check_in=yesterday, check_out=today',
            'HTTP 422 dengan error tanggal',
            function () {
                $resp = $this->req('GET', '/api/v1/rooms/availability?check_in=' . now()->subDay()->format('Y-m-d') . '&check_out=' . now()->format('Y-m-d'), $this->admin);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $g = 'Layanan Tambahan';

        $this->case($g, 'Admin membuat layanan', 'Layanan (laundry dll.) bisa ditagihkan ke reservasi.',
            '1) POST /api/v1/services', 'name=QA Laundry, price=25000, unit=kg',
            'HTTP 201',
            function () {
                $resp = $this->req('POST', '/api/v1/services', $this->admin, ['name' => 'QA Laundry', 'price' => 25000, 'unit' => 'kg']);

                return [$this->describe($resp), $resp->getStatusCode() === 201];
            });

        $this->case($g, 'Menghapus layanan = nonaktif, bukan hapus baris', 'Histori booking_services harus tetap terbaca.',
            '1) DELETE /services/{id} 2) PATCH /services/{id}/activate', 'id layanan QA Laundry',
            'DELETE HTTP 200 dengan data.is_active=false; baris tetap ada; activate mengaktifkan lagi',
            function () {
                $service = Service::where('name', 'QA Laundry')->first();
                $del     = $this->req('DELETE', "/api/v1/services/{$service->id}", $this->admin);
                $exists  = Service::whereKey($service->id)->exists();
                $react   = $this->req('PATCH', "/api/v1/services/{$service->id}/activate", $this->admin);

                return ['DELETE=' . $this->describe($del) . ' | baris ada=' . ($exists ? 'ya' : 'TIDAK') . ' | activate=' . $react->getStatusCode(),
                    $del->getStatusCode() === 200 && $del->json('data.is_active') === false && $exists && $react->getStatusCode() === 200 && $react->json('data.is_active') === true];
            });

        $this->case($g, 'Endpoint layanan per reservasi (dokumen)', 'AGENTS.md bagian 8 mencantumkan POST /bookings/{id}/services.',
            '1) booking 2) POST /bookings/{id}/services', 'service_id layanan QA, qty 2',
            'HTTP 200/201 karena tercantum pada daftar endpoint',
            function () {
                $service = Service::where('name', 'QA Laundry')->first();
                $service->update(['is_active' => true]);
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/services", $this->admin, [
                    'services' => [['service_id' => $service->id, 'qty' => 2]],
                ]);

                return [$this->describe($resp), $resp->getStatusCode() < 300,
                    'Layanan saat ini hanya bisa ditagih lewat payload services[] pada POST /bookings.'];
            });

        $this->case($g, 'Layanan pada payload reservasi + snapshot harga', 'Perubahan harga master tidak mengubah tagihan lama (AGENTS.md 4.3).',
            '1) POST /bookings dengan services[] 2) ubah harga master 3) cek booking_services', 'laundry 25.000 x 2, lalu master jadi 99.000',
            'HTTP 201; price_at_transaction=25.000; quantity=2; total baris=50.000; subtotal invoice=250.000',
            function () {
                $n  = ++$this->roomSeq;
                $service = Service::create(['name' => "QA Spa {$n}", 'price' => 25000, 'unit' => 'sesi', 'is_active' => true]);
                $ct = Customer::create(['name' => "Tamu Spa {$n}", 'phone' => '08129222' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
                $rt = RoomType::create(['name' => "QA Spa Tipe {$n}", 'price' => 200000, 'max_person' => 2]);
                $rm = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QS' . $n]);

                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $ct->id, 'source' => 'walk_in',
                    'check_in' => now()->format('Y-m-d'), 'check_out' => now()->addDay()->format('Y-m-d'),
                    'guest_count' => 1, 'rooms' => [$rm->id], 'guests' => [['name' => "Tamu Spa {$n}", 'is_primary' => true]],
                    'services' => [['service_id' => $service->id, 'qty' => 2]],
                ]);
                $service->update(['price' => 99000]);
                $snap = DB::table('booking_services')->where('booking_id', $resp->json('data.id'))->first();
                $inv  = Booking::find($resp->json('data.id'))?->invoice;

                return [$this->describe($resp) . ' | snapshot=' . ($snap ? $snap->price_at_transaction : 'TIDAK ADA')
                    . ', quantity=' . ($snap?->quantity ?? '-') . ', total baris=' . ($snap?->total ?? '-')
                    . ', subtotal invoice=' . ($inv ? $this->money((float) $inv->subtotal) : '-'),
                    $resp->getStatusCode() === 201 && $snap && (float) $snap->price_at_transaction === 25000.0
                        && (int) $snap->quantity === 2 && (float) $snap->total === 50000.0
                        && $inv && (float) $inv->subtotal === 250000.0,
                    'Snapshot harga & total tagihan benar, tetapi kolom quantity tercatat 1 walau permintaan qty=2: pemetaan data baru memakai kunci "qty", kolom tabelnya "quantity". Nominal tetap 50.000 (2 x 25.000), jadi yang salah adalah jejak kuantitas di histori.'];
            });
    }

    // =====================================================================
    // 5. Pelanggan / tamu
    // =====================================================================

    private function customerCases(): void
    {
        $g = 'Pelanggan';

        $this->case($g, 'Membuat data tamu', 'Data tamu dipakai ulang antar reservasi.',
            '1) POST /api/v1/customers', 'name=Tamu Satu, phone=081231110001, ktp=3201000000000001',
            'HTTP 201 + id',
            function () {
                $resp = $this->req('POST', '/api/v1/customers', $this->pegawai, [
                    'name' => 'Tamu Satu', 'phone' => '081231110001', 'ktp' => '3201000000000001', 'email' => 'tamu1@qa.test',
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 201];
            });

        $this->case($g, 'Pencarian tamu berbasis kata kunci', 'Kasir mencari tamu lama agar tidak membuat data ganda.',
            '1) GET /customers?search=Tamu Satu', 'search=Tamu Satu',
            'HTTP 200 dan minimal satu baris hasil yang cocok',
            function () {
                $resp = $this->req('GET', '/api/v1/customers?search=' . urlencode('Tamu Satu'), $this->pegawai);
                $rows = $resp->json('data.data') ?? $resp->json('data') ?? [];

                return [$this->describe($resp) . ' | jumlah hasil=' . count((array) $rows), $resp->getStatusCode() === 200 && count((array) $rows) >= 1];
            });

        $this->case($g, 'KTP dienkripsi di database', 'PII tidak boleh terbaca di DB (AGENTS.md 4.3).',
            '1) buat tamu 2) baca kolom customers.ktp mentah', 'ktp=3201000000000001',
            'Raw DB != nomor KTP; model bisa mendekripsi kembali',
            function () {
                $customer = Customer::create(['name' => 'Tamu Enkripsi', 'phone' => '081231110002', 'ktp' => '3201000000000001']);
                $raw = (string) DB::table('customers')->where('id', $customer->id)->value('ktp');
                $api = $this->req('GET', "/api/v1/customers/{$customer->id}", $this->admin)->json('data.ktp');
                $listedResponse = $this->req('GET', '/api/v1/customers?search=' . urlencode('Tamu Enkripsi'), $this->admin);
                $listed = $listedResponse->json('data.0.ktp') ?? $listedResponse->json('data.data.0.ktp');

                return ['raw=' . substr($raw, 0, 24) . '… | detail=' . $api . ' | list=' . $listed,
                    $raw !== '3201000000000001'
                        && $api === '3201000000000001'
                        && $listed === '3201••••••••0001',
                    'Database tetap terenkripsi; list API melakukan masking KTP.'];
            });

        $this->case($g, 'Tamu dengan histori tidak bisa dihapus', 'Riwayat harus tersimpan untuk laporan/audit.',
            '1) booking 2) DELETE /customers/{id}', 'customer dengan 1 reservasi',
            'HTTP 422 dengan pesan penolakan',
            function () {
                $b = $this->mkBooking();
                $resp = $this->req('DELETE', "/api/v1/customers/{$b['customer']->id}", $this->admin);

                return [$this->describe($resp), $resp->getStatusCode() === 422 && Customer::find($b['customer']->id) !== null];
            });

        $this->case($g, 'Tamu tanpa histori boleh dihapus', 'Sekalian membersihkan data salah input.',
            '1) POST tamu 2) DELETE /customers/{id}', 'customer baru tanpa booking',
            'HTTP 200 dan baris hilang',
            function () {
                $c = Customer::create(['name' => 'Tamu Salah Input', 'phone' => '081231110003']);
                $resp = $this->req('DELETE', "/api/v1/customers/{$c->id}", $this->admin);

                return [$this->describe($resp), $resp->getStatusCode() === 200 && Customer::find($c->id) === null];
            });
    }

    // =====================================================================
    // 6. Reservasi
    // =====================================================================

    private function bookingCases(): void
    {
        $g = 'Reservasi';

        $this->case($g, 'Reservasi 1 kamar 2 malam', 'Harga dihitung server, bukan diterima dari frontend (AGENTS.md butir 4).',
            '1) POST /api/v1/bookings 2) cek bookings & booking_rooms', '1 kamar @500.000, 2 malam, guest_count=1',
            'HTTP 201; booking_code terisi; nights=2; total = 1.000.000 + pajak 11% = 1.110.000',
            function () {
                $b  = $this->mkBooking(2, 500000);
                $br = DB::table('booking_rooms')->where('booking_id', $b['booking']->id)->first();

                return [$this->describe($b['response']) . ' | code=' . $b['booking']->booking_code . ', nights=' . $br->nights
                    . ', total=' . $this->money((float) $b['booking']->total_price),
                    $b['response']->getStatusCode() === 201 && (int) $br->nights === 2 && (float) $b['booking']->total_price === 1110000.0];
            });

        $this->case($g, 'Reservasi multi-kamar', 'Satu booking bisa memakai beberapa kamar sekaligus.',
            '1) buat 2 kamar 2) POST /bookings dengan rooms[2] + room_id per penghuni', '2 kamar @300.000 x 2 malam, guest_count=3',
            'HTTP 201; 2 baris booking_rooms; total = 1.200.000 + pajak; tiap penghuni tertaut kamar',
            function () {
                $n        = ++$this->roomSeq;
                $customer = Customer::create(['name' => "Tamu Multi {$n}", 'phone' => '0812900' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
                $type     = RoomType::create(['name' => "QA Multi {$n}", 'price' => 300000, 'max_person' => 2]);
                $r1       = Room::create(['room_type_id' => $type->id, 'room_number' => 'QM' . $n . 'A']);
                $r2       = Room::create(['room_type_id' => $type->id, 'room_number' => 'QM' . $n . 'B']);

                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $customer->id, 'source' => 'walk_in',
                    'check_in' => now()->format('Y-m-d'), 'check_out' => now()->addDays(2)->format('Y-m-d'),
                    'guest_count' => 3, 'rooms' => [$r1->id, $r2->id],
                    'guests' => [
                        ['name' => "Tamu Multi {$n}", 'is_primary' => true, 'room_id' => $r1->id],
                        ['name' => "Pendamping A {$n}", 'is_primary' => false, 'room_id' => $r1->id],
                        ['name' => "Pendamping B {$n}", 'is_primary' => false, 'room_id' => $r2->id],
                    ],
                ]);
                $booking = Booking::find($resp->json('data.id'));
                $count   = DB::table('booking_rooms')->where('booking_id', $booking?->id)->count();
                $expected = 1200000 * 1.11;
                $placed   = $booking ? DB::table('booking_guests')->where('booking_id', $booking->id)->whereNotNull('booking_room_id')->count() : 0;

                return [$this->describe($resp) . ' | baris booking_rooms=' . $count . ', penghuni tertaut kamar=' . $placed . ', total=' . $this->money((float) $booking?->total_price),
                    $resp->getStatusCode() === 201 && $count === 2 && $placed === 3 && abs((float) $booking->total_price - $expected) < 1.0,
                    'guest_count 3 diizinkan karena total kapasitas 2 kamar = 4 orang (2 + 2).'];
            });

        $this->case($g, 'Multi-kamar wajib penempatan', 'Tanpa room_id, "siapa tidur di kamar mana" tidak bisa dipertanggungjawabkan.',
            '1) POST /bookings rooms[2] tanpa guests[].room_id', '2 kamar, 1 penghuni tanpa kamar',
            'HTTP 422 dengan pesan pilih kamar untuk setiap penghuni',
            function () {
                $n        = ++$this->roomSeq;
                $customer = Customer::create(['name' => "Tamu Tanpa Kamar {$n}", 'phone' => '0812955' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
                $type     = RoomType::create(['name' => "QA Tanpa Kamar {$n}", 'price' => 250000, 'max_person' => 2]);
                $r1       = Room::create(['room_type_id' => $type->id, 'room_number' => 'QN' . $n . 'A']);
                $r2       = Room::create(['room_type_id' => $type->id, 'room_number' => 'QN' . $n . 'B']);

                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $customer->id, 'source' => 'walk_in',
                    'check_in' => now()->addDays(20)->format('Y-m-d'), 'check_out' => now()->addDays(21)->format('Y-m-d'),
                    'guest_count' => 2, 'rooms' => [$r1->id, $r2->id],
                    'guests' => [['name' => "Tamu Tanpa Kamar {$n}", 'is_primary' => true]],
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Satu kamar ditautkan otomatis', 'Penempatan tidak ambigu bila reservasi cuma pakai satu kamar.',
            '1) POST /bookings rooms[1] tanpa room_id 2) cek booking_guests', '1 kamar, 1 penghuni',
            'HTTP 201 dan booking_room_id penghuni terisi sendiri',
            function () {
                $b = $this->mkBooking(1, 250000);
                $guest = DB::table('booking_guests')->where('booking_id', $b['booking']->id)->first();

                return ['booking=' . ($b['booking']->booking_code ?? '-') . ', booking_room_id=' . ($guest->booking_room_id ?? 'NULL'),
                    $b['response']->getStatusCode() === 201 && !empty($guest->booking_room_id)];
            });

        $this->case($g, 'Cegah double booking (irisan tanggal)', 'Kamar yang sama tidak bisa dijual dua kali pada periode yang tumpang tindih.',
            '1) POST booking kamar QA-300 (hari ini +3) 2) POST booking kamar sama (hari ini +1)', 'check_out lebih awal tapi beririsan',
            'HTTP 409 dengan pesan kamar tidak tersedia',
            function () {
                $b    = $this->mkBooking(3);
                $room = $b['room'];
                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => Customer::create(['name' => 'Tamu Bentrok', 'phone' => '081291110001'])->id,
                    'source' => 'walk_in',
                    'check_in' => now()->format('Y-m-d'), 'check_out' => now()->addDay()->format('Y-m-d'),
                    'guest_count' => 1, 'rooms' => [$room->id],
                    'guests' => [['name' => 'Tamu Bentrok', 'is_primary' => true]],
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 409];
            });

        $this->case($g, 'Tanggal check-out harus setelah check-in', 'Validasi dasar periode menginap.',
            '1) POST /bookings dengan check_out < check_in', 'check_in=+5, check_out=+4',
            'HTTP 422 dengan error pada field check_out',
            function () {
                $n   = ++$this->roomSeq;
                $ct  = Customer::create(['name' => 'Tamu Tanggal', 'phone' => '081291110002']);
                $rt  = RoomType::create(['name' => "QA Tgl {$n}", 'price' => 200000, 'max_person' => 2]);
                $rm  = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QT' . $n]);
                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $ct->id, 'source' => 'walk_in',
                    'check_in' => now()->addDays(5)->format('Y-m-d'), 'check_out' => now()->addDays(4)->format('Y-m-d'),
                    'guest_count' => 1, 'rooms' => [$rm->id], 'guests' => [['name' => 'Tamu Tanggal', 'is_primary' => true]],
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Jumlah tamu melebihi kapasitas kamar', 'guest_count dibatasi total max_person kamar terpilih.',
            '1) POST /bookings 1 kamar max 2 orang', 'guest_count=5',
            'HTTP 422 dengan pesan kapasitas',
            function () {
                $n  = ++$this->roomSeq;
                $ct = Customer::create(['name' => 'Tamu Keroyokan', 'phone' => '081291110003']);
                $rt = RoomType::create(['name' => "QA Kecil {$n}", 'price' => 150000, 'max_person' => 2]);
                $rm = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QK' . $n]);
                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $ct->id, 'source' => 'walk_in',
                    'check_in' => now()->addDays(10)->format('Y-m-d'), 'check_out' => now()->addDays(11)->format('Y-m-d'),
                    'guest_count' => 5, 'rooms' => [$rm->id], 'guests' => [['name' => 'Tamu Keroyokan', 'is_primary' => true]],
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Diskon tidak boleh melebihi subtotal', 'Aturan AGENTS.md butir 4.',
            '1) POST /bookings dengan discount_amount > subtotal', 'subtotal 200.000, diskon 500.000',
            'HTTP 422 dengan pesan batas diskon',
            function () {
                $b = $this->mkBooking(1, 200000);
                $n = ++$this->roomSeq;
                $ct = Customer::create(['name' => 'Tamu Diskon QA', 'phone' => '081291110004']);
                $rt = RoomType::create(['name' => "QA Diskon {$n}", 'price' => 200000, 'max_person' => 2]);
                $rm = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QD' . $n]);
                unset($b);

                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $ct->id, 'source' => 'walk_in',
                    'check_in' => now()->format('Y-m-d'), 'check_out' => now()->addDay()->format('Y-m-d'),
                    'guest_count' => 1, 'rooms' => [$rm->id], 'guests' => [['name' => 'Tamu Diskon QA', 'is_primary' => true]],
                    'discount_amount' => 500000,
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Booking tanpa nama tamu ditolak', 'Data tamu wajib untuk legal record.',
            '1) POST /bookings dengan guests[].name kosong', 'guests=[{name:""}]',
            'HTTP 422 (validasi field guests.0.name)',
            function () {
                $n  = ++$this->roomSeq;
                $ct = Customer::create(['name' => 'Tamu Kosong', 'phone' => '081291110005']);
                $rt = RoomType::create(['name' => "QA Kosong {$n}", 'price' => 100000, 'max_person' => 2]);
                $rm = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QKO' . $n]);
                $resp = $this->req('POST', '/api/v1/bookings', $this->admin, [
                    'customer_id' => $ct->id, 'source' => 'walk_in',
                    'check_in' => now()->format('Y-m-d'), 'check_out' => now()->addDay()->format('Y-m-d'),
                    'guest_count' => 1, 'rooms' => [$rm->id], 'guests' => [['name' => '', 'is_primary' => true]],
                ]);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Invoice otomatis dibuat untuk reservasi', 'Invoice draft mengiringi reservasi (default AGENTS.md butir 5).',
            '1) POST /bookings 2) cek invoices', 'booking QA baru',
            'Ada 1 invoice dengan subtotal/tax/total konsisten',
            function () {
                $b = $this->mkBooking(2, 400000)['booking'];
                $inv = $b->invoice;

                return [$inv ? 'invoice ' . $inv->invoice_number . ' status=' . $inv->status->value
                    . ' subtotal=' . $this->money((float) $inv->subtotal) . ' tax=' . $this->money((float) $inv->tax)
                    . ' total=' . $this->money((float) $inv->total) : 'TIDAK ADA invoice dibuat otomatis',
                    $inv !== null && (float) $inv->total === (float) $b->total_price && (float) $inv->subtotal === 800000.0];
            });

        $this->case($g, 'Konfirmasi reservasi mencatat audit', 'Jalur resmi untuk booking kredit/Traveloka.',
            '1) POST /bookings/{id}/confirm 2) cek audit_logs', 'reason=Dibayar ke hotel via Traveloka',
            'HTTP 200, status=confirmed, audit booking.confirmed',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/confirm", $this->pegawai, ['reason' => 'Dibayar ke hotel via Traveloka']);
                $log  = AuditLog::where('action', 'booking.confirmed')->where('auditable_id', $b->id)->exists();

                return [$this->describe($resp) . ' | status DB=' . $b->fresh()->status->value . ', audit=' . ($log ? 'ya' : 'TIDAK'),
                    $resp->getStatusCode() === 200 && $b->fresh()->status === BookingStatus::Confirmed && $log];
            });

        $this->case($g, 'Konfirmasi ulang ditolak', 'Hanya booking pending yang bisa dikonfirmasi.',
            '1) confirm 2) confirm lagi', 'booking sudah confirmed',
            'HTTP 422 dengan pesan hanya reservasi pending',
            function () {
                $b = $this->mkBooking()['booking'];
                $this->req('POST', "/api/v1/bookings/{$b->id}/confirm", $this->admin);
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/confirm", $this->admin);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Batal reservasi membebaskan kamar', 'Pembatalan mengisi cancel_reason/cancelled_at + invoice void.',
            '1) booking 2) cancel 3) cek invoice & availability', 'cancel_reason=Tamu membatalkan lewat telepon',
            'HTTP 200; status=cancelled; invoice void; kamar muncul lagi di availability',
            function () {
                $b    = $this->mkBooking(2, 300000);
                $inv  = $b['booking']->invoice;
                $resp = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/cancel", $this->admin, ['cancel_reason' => 'Tamu membatalkan lewat telepon']);
                $fresh = $b['booking']->fresh();
                $avail = $this->req('GET', '/api/v1/rooms/availability?check_in=' . now()->format('Y-m-d') . '&check_out=' . now()->addDays(2)->format('Y-m-d'), $this->admin);
                $found = collect($avail->json('data') ?? [])->firstWhere('id', $b['room']->id);

                return [$this->describe($resp) . ' | status=' . $fresh->status->value . ', cancelled_at=' . ($fresh->cancelled_at ? 'terisi' : 'KOSONG')
                    . ', invoice=' . ($inv ? $inv->fresh()->status->value : 'tidak ada') . ', kamar tersedia lagi=' . ($found ? 'ya' : 'belum'),
                    $resp->getStatusCode() === 200 && $fresh->status === BookingStatus::Cancelled && $fresh->cancelled_at !== null
                        && ($inv === null || $inv->fresh()->status === InvoiceStatus::Void) && $found !== null];
            });

        $this->case($g, 'Batal tanpa alasan ditolak', 'cancel_reason wajib (AGENTS.md butir 5).',
            '1) POST /bookings/{id}/cancel tanpa body', 'cancel_reason kosong',
            'HTTP 422 dengan error field cancel_reason',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/cancel", $this->admin, []);

                return [$this->describe($resp) . ' | status DB=' . $b->fresh()->status->value,
                    $resp->getStatusCode() === 422 && $b->fresh()->status !== BookingStatus::Cancelled];
            });
    }

    // =====================================================================
    // 7. Check-in / check-out
    // =====================================================================

    private function checkInOutCases(): void
    {
        $g = 'Check-In / Check-Out';

        $this->case($g, 'Check-in ditolak saat reservasi pending', 'Belum lunas/belum dikonfirmasi = belum boleh menginap.',
            '1) POST /bookings 2) POST /bookings/{id}/check-in', 'booking pending tanpa pembayaran',
            'HTTP 422 dengan pesan reservasi belum terkonfirmasi',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/check-in", $this->admin);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Check-in memindahkan status kamar', 'Alur resmi: booking confirmed -> checked_in, kamar -> occupied.',
            '1) booking 2) bayar tunai penuh 3) check-in 4) cek DB', 'metode cash (langsung verified)',
            'HTTP 200; booking checked_in; kamar occupied; tercatat di room_status_logs',
            function () {
                $b    = $this->mkBooking(1, 500000);
                $inv  = $this->invoiceTotal($b['booking']);
                $pay  = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/payments", $this->admin, ['amount' => $inv, 'method' => 'cash', 'type' => 'final']);
                $resp = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-in", $this->admin);
                $log  = RoomStatusLog::where('room_id', $b['room']->id)->latest('id')->first();

                return ['bayar=' . $pay->getStatusCode() . ' (' . $this->money($inv) . '), ' . $this->describe($resp)
                    . ' | booking=' . $b['booking']->fresh()->status->value . ', kamar=' . $b['room']->fresh()->status->value
                    . ', log=' . $this->strv($log?->to_status),
                    $resp->getStatusCode() === 200 && $b['booking']->fresh()->status === BookingStatus::CheckedIn
                        && $b['room']->fresh()->status === RoomStatus::Occupied && $this->strv($log?->to_status) === RoomStatus::Occupied->value];
            });

        $this->case($g, 'Check-out saat tagihan belum lunas ditolak', 'Sisa tagihan harus dilunasi dulu (AGENTS.md butir 6).',
            '1) booking 2) DP tunai sebagian 3) confirm+check-in 4) check-out', 'DP 300.000 dari total 1.110.000',
            'HTTP 422 dengan pesan sisa tagihan',
            function () {
                Setting::first()->update(['min_dp_percentage' => 0]);
                $b    = $this->mkBooking(2, 500000);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/payments", $this->admin, ['amount' => 300000, 'method' => 'cash', 'type' => 'dp']);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/confirm", $this->admin);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-in", $this->admin);
                $resp = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-out", $this->admin);

                return [$this->describe($resp) . ' | booking=' . $b['booking']->fresh()->status->value,
                    $resp->getStatusCode() === 422 && $b['booking']->fresh()->status === BookingStatus::CheckedIn];
            });

        $this->case($g, 'Check-out penuh membersihkan kamar', 'Check-out -> checked_out, kamar cleaning, housekeeping task dibuat.',
            '1) check-in 2) lunasi sisanya 3) check-out 4) cek DB', 'pelunasan tunai',
            'HTTP 200; booking checked_out; kamar cleaning; 1 task housekeeping',
            function () {
                $b   = $this->mkBooking(1, 600000);
                $tot = $this->invoiceTotal($b['booking']);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/payments", $this->admin, ['amount' => $tot, 'method' => 'cash', 'type' => 'final']);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-in", $this->admin);
                $resp = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-out", $this->admin);
                $task = HousekeepingTask::where('room_id', $b['room']->id)->latest('id')->first();

                return [$this->describe($resp) . ' | booking=' . $b['booking']->fresh()->status->value
                    . ', kamar=' . $b['room']->fresh()->status->value
                    . ', task=' . ($task ? $task->status->value : 'TIDAK DIBUAT'),
                    $resp->getStatusCode() === 200 && $b['booking']->fresh()->status === BookingStatus::CheckedOut
                        && $b['room']->fresh()->status === RoomStatus::Cleaning && $task !== null];
            });

        $this->case($g, 'Check-out sebelum check-in ditolak', 'Status harus berurutan (confirmed != checked_in).',
            '1) booking 2) bayar penuh 3) POST check-out tanpa check-in', 'booking confirmed',
            'HTTP 422 dengan pesan status tidak sesuai',
            function () {
                $b   = $this->mkBooking(1, 250000);
                $tot = $this->invoiceTotal($b['booking']);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/payments", $this->admin, ['amount' => $tot, 'method' => 'cash', 'type' => 'final']);
                $resp = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-out", $this->admin);

                return [$this->describe($resp) . ' | booking=' . $b['booking']->fresh()->status->value,
                    $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Booking selesai tidak bisa dibatalkan', 'Koreksi hanya lewat jalur khusus admin.',
            '1) sampai checked_out 2) POST cancel', 'cancel_reason=Salah input',
            'HTTP 422 dengan pesan tidak bisa dibatalkan lagi',
            function () {
                $b   = $this->mkBooking(1, 300000);
                $tot = $this->invoiceTotal($b['booking']);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/payments", $this->admin, ['amount' => $tot, 'method' => 'cash', 'type' => 'final']);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-in", $this->admin);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/check-out", $this->admin);
                $resp = $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/cancel", $this->admin, ['cancel_reason' => 'Salah input']);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Ambang DP memblokir pegawai, admin bisa override', 'min_dp_percentage dipakai saat konfirmasi.',
            '1) set min_dp 50% 2) confirm oleh pegawai 3) confirm oleh admin', 'tagihan 1.110.000, DP terverifikasi 0',
            'Pegawai HTTP 422 (butuh 555.000); admin HTTP 200 dengan alasan',
            function () {
                Setting::first()->update(['min_dp_percentage' => 50]);
                $b   = $this->mkBooking(2, 500000)['booking'];
                $byP = $this->req('POST', "/api/v1/bookings/{$b->id}/confirm", $this->pegawai);
                $byA = $this->req('POST', "/api/v1/bookings/{$b->id}/confirm", $this->admin, ['reason' => 'Kredit disetujui owner']);
                Setting::first()->update(['min_dp_percentage' => 0]);

                return ['pegawai=' . $this->describe($byP) . ' | admin=' . $this->describe($byA),
                    $byP->getStatusCode() === 422 && $byA->getStatusCode() === 200 && $b->fresh()->status === BookingStatus::Confirmed];
            });
    }

    // =====================================================================
    // 8. Shift & pembayaran manual
    // =====================================================================

    private function shiftPaymentCases(): void
    {
        $g = 'Shift & Pembayaran';

        // Kasus sebelumnya ada yang membuka shift pegawai; tutup dulu supaya
        // uji "pegawai tanpa shift" benar-benar berjalan tanpa shift open.
        $this->req('POST', '/api/v1/shifts/end', $this->pegawai);

        $this->case($g, 'Pegawai tanpa shift tidak bisa mencatat bayar', 'Akuntabilitas per shift (AGENTS.md butir 9).',
            '1) POST /bookings/{id}/payments sebagai pegawai tanpa shift open', 'amount=100.000, method=cash',
            'HTTP 403 dengan pesan wajib memulai shift',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->pegawai, ['amount' => 100000, 'method' => 'cash', 'type' => 'dp']);

                return [$this->describe($resp), $resp->getStatusCode() === 403];
            });

        $this->case($g, 'Mulai shift dan pembacaan shift berjalan', 'Satu sesi kerja tercatat sebagai shift open.',
            '1) POST /shifts/start 2) GET /shifts/current', 'role=pegawai',
            'Keduanya sukses dan current berisi status open',
            function () {
                $start   = $this->req('POST', '/api/v1/shifts/start', $this->pegawai);
                $current = $this->req('GET', '/api/v1/shifts/current', $this->pegawai);
                $status  = $current->json('data.status') ?? $current->json('data.shift.status');

                return ['start=' . $this->describe($start) . ' | current=' . $this->describe($current),
                    $start->getStatusCode() < 300 && $current->getStatusCode() === 200 && $status === 'open'];
            });

        $this->case($g, 'Shift ganda untuk satu pegawai ditolak', 'Satu pegawai hanya boleh satu shift open.',
            '1) start 2) start lagi', 'role=pegawai',
            'HTTP 422 dengan pesan shift masih berjalan',
            function () {
                $this->req('POST', '/api/v1/shifts/start', $this->pegawai);
                $resp = $this->req('POST', '/api/v1/shifts/start', $this->pegawai);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Pembayaran menempel ke shift aktif', 'Tiap transaksi kasir punya shift_id.',
            '1) start shift 2) catat tunai 3) cek payments.shift_id', 'amount=200.000, method=cash',
            'HTTP 201 dan shift_id = id shift open pegawai',
            function () {
                $shiftId = DB::table('shifts')->where('user_id', $this->pegawai->id)->where('status', 'open')->latest('id')->value('id');
                $b       = $this->mkBooking()['booking'];
                $resp    = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->pegawai, ['amount' => 200000, 'method' => 'cash', 'type' => 'dp']);
                $row     = DB::table('payments')->where('id', $resp->json('data.id'))->first();

                return [$this->describe($resp) . ' | shift_id=' . ($row->shift_id ?? 'NULL') . ' vs shift open=' . $shiftId,
                    $resp->getStatusCode() === 201 && $row && (int) $row->shift_id === (int) $shiftId];
            });

        $this->case($g, 'Tunai langsung terverifikasi', 'Perilaku sistem saat ini untuk metode cash.',
            '1) catat cash oleh pegawai ber-shift', 'amount=100.000, type=dp',
            'HTTP 201 dengan status verified (tunai tidak menunggu admin)',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->pegawai, ['amount' => 100000, 'method' => 'cash', 'type' => 'dp']);

                return [$this->describe($resp) . ' | status=' . $resp->json('data.status'),
                    $resp->getStatusCode() === 201 && $resp->json('data.status') === PaymentStatus::Verified->value,
                    'AGENTS.md butir 6 menandai kebijakan ini [TENTUKAN]; hasil aktual: langsung verified.'];
            });

        $this->case($g, 'Transfer masuk sebagai pending', 'Butuh verifikasi admin sebelum dihitung sebagai pendapatan.',
            '1) catat transfer oleh admin', 'amount=300.000, method=transfer, reference=TRF-QA-001',
            'HTTP 201 dengan status pending',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 300000, 'method' => 'transfer', 'type' => 'dp', 'reference_number' => 'TRF-QA-001']);

                return [$this->describe($resp) . ' | status=' . $resp->json('data.status'),
                    $resp->getStatusCode() === 201 && $resp->json('data.status') === PaymentStatus::Pending->value];
            });

        $this->case($g, 'Nominal melebihi tagihan ditolak', 'Validasi nominal sebelum status berubah (KNF-14).',
            '1) booking 2) POST payment jauh di atas total', 'amount=5.000.000 vs tagihan 1.110.000',
            'HTTP 422 dan tidak ada payment tercatat',
            function () {
                $b    = $this->mkBooking(2, 500000)['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 5000000, 'method' => 'cash', 'type' => 'final']);

                return [$this->describe($resp) . ' | jumlah payment=' . Payment::where('booking_id', $b->id)->count(),
                    $resp->getStatusCode() === 422 && Payment::where('booking_id', $b->id)->count() === 0];
            });

        $this->case($g, 'Pembayaran manual diblokir saat masih ada pending', 'Mencegah menagih dobel transfer yang belum dicek.',
            '1) catat transfer (pending) 2) catat tunai pelunasan', 'pending TRF-QA-002 lalu cash 610.000',
            'HTTP 422 dengan pesan masih ada pembayaran menunggu verifikasi',
            function () {
                $b    = $this->mkBooking(2, 500000)['booking'];
                $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 500000, 'method' => 'transfer', 'type' => 'dp', 'reference_number' => 'TRF-QA-002']);
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 610000, 'method' => 'cash', 'type' => 'final']);

                return [$this->describe($resp), $resp->getStatusCode() === 422 && Payment::where('booking_id', $b->id)->count() === 1];
            });

        $this->case($g, 'Tolak pembayaran membuka jalan bayar ulang', 'Penolakan melepas blokir pending.',
            '1) pending 2) PATCH reject 3) catat tunai', 'reason dikirim dari dashboard',
            'Reject HTTP 200 status failed; pembayaran tunai berikutnya HTTP 201',
            function () {
                $b   = $this->mkBooking(2, 500000)['booking'];
                $pid = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 500000, 'method' => 'transfer', 'type' => 'dp', 'reference_number' => 'TRF-QA-003'])->json('data.id');
                $rej  = $this->req('PATCH', "/api/v1/payments/{$pid}/reject", $this->admin, ['reason' => 'Dana tidak sesuai mutasi']);
                $cash = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 610000, 'method' => 'cash', 'type' => 'final']);

                return ['reject=' . $this->describe($rej) . ' (status=' . $rej->json('data.status') . ') | cash=' . $this->describe($cash),
                    $rej->getStatusCode() === 200 && $rej->json('data.status') === PaymentStatus::Failed->value && $cash->getStatusCode() === 201];
            });

        $this->case($g, 'Upload bukti transfer', 'Bukti bayar disimpan untuk audit admin.',
            '1) catat transfer 2) POST /payments/{id}/proof (multipart)', 'file=bukti-transfer.jpg 12KB',
            'HTTP 200 dengan path bukti tersimpan di storage',
            function () {
                Storage::fake('local');
                $b    = $this->mkBooking()['booking'];
                $pid  = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 250000, 'method' => 'transfer', 'type' => 'dp', 'reference_number' => 'TRF-QA-004'])->json('data.id');
                $resp = $this->actingAs($this->admin)->postJson("/api/v1/payments/{$pid}/proof", [
                    'proof' => UploadedFile::fake()->create('bukti-transfer.jpg', 12, 'image/jpeg'),
                ]);
                $saved = (string) (Payment::find($pid)?->proof_of_payment ?? '');

                return [$this->describe($resp) . ' | path=' . ($saved ?: 'KOSONG')
                    . ', file di storage=' . ($this->proofOnDisk($saved) ? 'ada' : 'TIDAK'),
                    $resp->getStatusCode() === 200 && $saved !== '' && $this->proofOnDisk($saved),
                    'Nomor referensi unik + nominal cocok membuat bukti ini terverifikasi otomatis (lihat pesan response).'];
            });

        $this->case($g, 'Reversal pembayaran butuh alasan', 'Pembatalan pembayaran verified (post-audit).',
            '1) reverse tanpa reason 2) reverse dengan reason', 'reason=Dana ternyata tidak masuk',
            'Tanpa reason HTTP 422; dengan reason HTTP 200 + status dibatalkan',
            function () {
                $b   = $this->mkBooking(1, 400000)['booking'];
                $pid = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 444000, 'method' => 'cash', 'type' => 'final'])->json('data.id');
                $bad = $this->req('POST', "/api/v1/payments/{$pid}/reverse", $this->admin, []);
                $ok  = $this->req('POST', "/api/v1/payments/{$pid}/reverse", $this->admin, ['reason' => 'Dana ternyata tidak masuk']);
                $row = Payment::find($pid);

                return ['tanpa reason=' . $this->describe($bad) . ' | dengan reason=' . $this->describe($ok)
                    . ' | status=' . ($row?->status?->value ?? '?'),
                    $bad->getStatusCode() === 422 && $ok->getStatusCode() === 200 && in_array($row?->status?->value, [PaymentStatus::Failed->value, 'reversed', 'voided'], true)];
            });

        $this->case($g, 'Akhiri shift menutup akses catat transaksi', 'Setelah end, pegawai harus buka shift baru.',
            '1) POST /shifts/end 2) catat pembayaran', 'role=pegawai',
            'End sukses dan pembayaran berikutnya HTTP 403',
            function () {
                $end   = $this->req('POST', '/api/v1/shifts/end', $this->pegawai);
                $b     = $this->mkBooking()['booking'];
                $again = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->pegawai, ['amount' => 50000, 'method' => 'cash', 'type' => 'dp']);

                return ['end=' . $this->describe($end) . ' | bayar=' . $this->describe($again),
                    $end->getStatusCode() < 300 && $again->getStatusCode() === 403];
            });

        $this->case($g, 'Verifikasi mengubah booking jadi confirmed', 'DP terverifikasi memicu konfirmasi otomatis.',
            '1) transfer DP 2) verify admin 3) cek status booking', 'amount=600.000, min_dp=0',
            'HTTP 200 verified dan booking status confirmed',
            function () {
                Setting::first()->update(['min_dp_percentage' => 0]);
                $b   = $this->mkBooking(2, 500000)['booking'];
                $pid = $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => 600000, 'method' => 'transfer', 'type' => 'dp', 'reference_number' => 'TRF-QA-005'])->json('data.id');
                $ver = $this->req('PATCH', "/api/v1/payments/{$pid}/verify", $this->admin);

                return [$this->describe($ver) . ' | booking=' . $b->fresh()->status->value
                    . ', audit=' . (AuditLog::where('action', 'payment.verified')->where('auditable_id', $pid)->exists() ? 'ya' : 'TIDAK'),
                    $ver->getStatusCode() === 200 && $b->fresh()->status === BookingStatus::Confirmed];
            });
    }

    // =====================================================================
    // 9. Invoice & laporan
    // =====================================================================

    private function invoiceReportCases(): void
    {
        $g = 'Invoice';

        $this->case($g, 'Daftar invoice dapat dibaca', 'Kasir mencari invoice per reservasi.',
            '1) buat booking 2) GET /api/v1/invoices', 'role=pegawai, tanpa filter',
            'HTTP 200 dengan minimal 1 baris',
            function () {
                $this->mkBooking();
                $resp = $this->req('GET', '/api/v1/invoices', $this->pegawai);
                $rows = $resp->json('data.data') ?? $resp->json('data') ?? [];

                return [$this->describe($resp) . ' | jumlah=' . count((array) $rows),
                    $resp->getStatusCode() === 200 && count((array) $rows) >= 1];
            });

        $this->case($g, 'Detail invoice memuat rincian tagihan', 'Subtotal, diskon, pajak, total harus terbaca utuh.',
            '1) GET /invoices/{id}', 'invoice booking QA',
            'HTTP 200 dengan kunci subtotal, tax, total',
            function () {
                $inv  = Invoice::first();
                $resp = $this->req('GET', "/api/v1/invoices/{$inv->id}", $this->admin);
                $d    = (array) $resp->json('data');

                return [$this->describe($resp) . ' | subtotal=' . ($d['subtotal'] ?? '-') . ', tax=' . ($d['tax'] ?? '-') . ', total=' . ($d['total'] ?? '-'),
                    $resp->getStatusCode() === 200 && isset($d['subtotal'], $d['tax'], $d['total'])];
            });

        $this->case($g, 'Hitung ulang invoice', 'Tagihan mengikuti perubahan layanan/diskon.',
            '1) POST /invoices/{id}/recalculate', 'invoice belum lunas',
            'HTTP 200, atau 422 bila invoice sudah paid',
            function () {
                $inv  = Invoice::first();
                $resp = $this->req('POST', "/api/v1/invoices/{$inv->id}/recalculate", $this->admin);

                return [$this->describe($resp) . ' | total=' . ($resp->json('data.total') ?? '-'),
                    $resp->getStatusCode() === 200 || ($resp->getStatusCode() === 422 && str_contains((string) $resp->json('message'), 'lunas'))];
            });

        $reports = [
            ['occupancy', 'Tingkat hunian kamar per periode.'],
            ['revenue', 'Pendapatan per metode bayar.'],
            ['reservations', 'Ringkasan reservasi per status.'],
            ['transactions', 'Daftar transaksi pembayaran.'],
            ['payment-methods', 'Komposisi metode pembayaran.'],
            ['shifts', 'Perhitungan kas per shift.'],
            ['sources', 'Reservasi per asal (walk-in, telepon, Traveloka).'],
            ['housekeeping', 'Progres tugas housekeeping.'],
        ];

        foreach ($reports as [$type, $desc]) {
            $this->case('Laporan', 'Laporan ' . $type, $desc,
                '1) GET /api/v1/reports/' . $type, 'role=admin',
                'HTTP 200 dengan objek data laporan',
                function () use ($type) {
                    $resp = $this->req('GET', '/api/v1/reports/' . $type, $this->admin);

                    return [$this->describe($resp), $resp->getStatusCode() === 200 && $resp->json('data') !== null];
                });
        }

        $this->case('Laporan', 'Filter periode laporan dihormati', 'From/to harus mengikuti query string.',
            '1) GET /reports/occupancy?from&to', 'from=2026-01-01, to=2026-01-31',
            'data.period.from/to sama dengan tanggal yang dikirim',
            function () {
                $resp = $this->req('GET', '/api/v1/reports/occupancy?from=2026-01-01&to=2026-01-31', $this->admin);

                return [$this->describe($resp) . ' | period=' . json_encode($resp->json('data.period')),
                    $resp->json('data.period.from') === '2026-01-01' && $resp->json('data.period.to') === '2026-01-31',
                    'Nama parameter yang dipakai backend: from & to (bukan start_date/end_date).'];
            });

        $this->case('Laporan', 'Export Excel (xlsx)', 'Laporan dapat diunduh sebagai Excel.',
            '1) GET /reports/transactions?format=xlsx', 'role=admin',
            'HTTP 200 + Content-Type spreadsheetml + lampiran .xlsx',
            function () {
                $resp = $this->actingAs($this->admin)->get('/api/v1/reports/transactions?format=xlsx');
                $ct   = (string) $resp->headers->get('Content-Type');
                $disp = (string) $resp->headers->get('Content-Disposition');
                $size = strlen((string) $resp->getContent());

                return ['HTTP ' . $resp->getStatusCode() . ' - Content-Type=' . $ct . ', disposition=' . $disp . ', byte=' . $size,
                    $resp->getStatusCode() === 200 && str_contains($ct, 'spreadsheetml') && str_contains($disp, '.xlsx'),
                    'Body streamed (BinaryFileResponse), jadi getContent() kosong di test—ukuran bukan indikator.'];
            });

        $this->case('Laporan', 'Export PDF', 'Laporan dapat diunduh sebagai PDF.',
            '1) GET /reports/revenue?format=pdf', 'role=admin',
            'HTTP 200 + Content-Type application/pdf + lampiran .pdf',
            function () {
                $resp = $this->actingAs($this->admin)->get('/api/v1/reports/revenue?format=pdf');
                $ct   = (string) $resp->headers->get('Content-Type');
                $disp = (string) $resp->headers->get('Content-Disposition');
                $size = strlen((string) $resp->getContent());

                return ['HTTP ' . $resp->getStatusCode() . ' - Content-Type=' . $ct . ', disposition=' . $disp . ', byte=' . $size,
                    $resp->getStatusCode() === 200 && str_contains($ct, 'application/pdf') && str_contains($disp, '.pdf')];
            });
    }

    // =====================================================================
    // 10. Housekeeping & maintenance
    // =====================================================================

    private function housekeepingMaintenanceCases(): void
    {
        $g = 'Housekeeping';

        $this->case($g, 'Daftar tugas housekeeping', 'Papan kerja bagian kebersihan.',
            '1) sampai check-out 2) GET /housekeeping-tasks', 'role=pegawai',
            'HTTP 200 dengan minimal 1 task (dibuat otomatis saat check-out)',
            function () {
                $b   = $this->mkBooking(1, 300000)['booking'];
                $tot = $this->invoiceTotal($b);
                $this->req('POST', "/api/v1/bookings/{$b->id}/payments", $this->admin, ['amount' => $tot, 'method' => 'cash', 'type' => 'final']);
                $this->req('POST', "/api/v1/bookings/{$b->id}/check-in", $this->admin);
                $this->req('POST', "/api/v1/bookings/{$b->id}/check-out", $this->admin);
                $resp = $this->req('GET', '/api/v1/housekeeping-tasks', $this->pegawai);
                $rows = $resp->json('data.data') ?? $resp->json('data') ?? [];

                return [$this->describe($resp) . ' | jumlah task=' . count((array) $rows),
                    $resp->getStatusCode() === 200 && count((array) $rows) >= 1];
            });

        $this->case($g, 'Pengerjaan tugas (in_progress)', 'Status kerja tercatat.',
            '1) PATCH /housekeeping-tasks/{id}', 'status=in_progress',
            'HTTP 200 dengan status in_progress',
            function () {
                $task = HousekeepingTask::first();
                $resp = $this->req('PATCH', "/api/v1/housekeeping-tasks/{$task->id}", $this->pegawai, ['status' => 'in_progress']);

                return [$this->describe($resp) . ' | status=' . $task->fresh()->status->value,
                    $resp->getStatusCode() === 200 && $task->fresh()->status->value === 'in_progress'];
            });

        $this->case($g, 'Tugas selesai membebaskan kamar', 'completed -> kamar available (AGENTS.md butir 5).',
            '1) PATCH status=completed 2) cek kamar', 'id task QA',
            'HTTP 200 dan kamar kembali available',
            function () {
                $task = HousekeepingTask::first();
                $resp = $this->req('PATCH', "/api/v1/housekeeping-tasks/{$task->id}", $this->pegawai, ['status' => 'completed']);
                $room = Room::find($task->room_id);

                return [$this->describe($resp) . ' | kamar=' . ($room?->status?->value ?? '?'),
                    $resp->getStatusCode() === 200 && $room?->status === RoomStatus::Available];
            });

        $g = 'Maintenance';

        $this->case($g, 'Lapor kerusakan oleh pegawai', 'Kamar rusak langsung dikunci dari penjualan.',
            '1) POST /maintenance 2) cek status kamar', 'room_id=kamar QA, description=AC bocor, priority=high',
            'HTTP 201 dan kamar berubah ke maintenance',
            function () {
                $n    = ++$this->roomSeq;
                $rt   = RoomType::create(['name' => "QA Rusak {$n}", 'price' => 200000, 'max_person' => 2]);
                $rm   = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QR' . $n]);
                $resp = $this->req('POST', '/api/v1/maintenance', $this->pegawai, ['room_id' => $rm->id, 'description' => 'AC bocor', 'priority' => 'high']);
                $room = $rm->fresh();

                return [$this->describe($resp) . ' | status kamar=' . $room->status->value,
                    $resp->getStatusCode() === 201 && $room->status === RoomStatus::Maintenance];
            });

        $this->case($g, 'Maintenance tanpa kamar ditolak', 'Validasi relasi wajib.',
            '1) POST /maintenance tanpa room_id', 'hanya description',
            'HTTP 422 dengan error room_id',
            function () {
                $resp = $this->req('POST', '/api/v1/maintenance', $this->pegawai, ['description' => 'Kran macet']);

                return [$this->describe($resp), $resp->getStatusCode() === 422];
            });

        $this->case($g, 'Selesai perbaikan membebaskan kamar', 'resolved -> kamar keluar dari maintenance.',
            '1) PATCH /maintenance/{id}/status', 'status=resolved, resolution_notes=Sudah diganti',
            'HTTP 200 dan kamar tidak lagi berstatus maintenance',
            function () {
                $task = DB::table('maintenance')->orderByDesc('id')->first();
                $resp = $this->req('PATCH', "/api/v1/maintenance/{$task->id}/status", $this->pegawai, ['status' => 'resolved', 'resolution_notes' => 'Sudah diganti']);
                $room = Room::find($task->room_id);

                return [$this->describe($resp) . ' | kamar=' . ($room?->status?->value ?? '?'),
                    $resp->getStatusCode() === 200 && $room?->status !== RoomStatus::Maintenance];
            });
    }

    // =====================================================================
    // 11. Cases umum: validasi, pagination, kelengkapan endpoint
    // =====================================================================

    private function miscCases(): void
    {
        $g = 'Umum & Keamanan';

        $this->case($g, 'Format body error validasi', 'Kontrak API: 422 dengan message + errors per field.',
            '1) POST /users dengan email tidak valid', 'email="bukan-email", password="short"',
            'HTTP 422 + kunci message & errors.email',
            function () {
                $resp = $this->req('POST', '/api/v1/users', $this->admin, ['name' => 'X', 'email' => 'bukan-email', 'password' => 'short', 'role' => 'pegawai']);
                $json = (array) $resp->json();

                return [$this->describe($resp) . ' | keys=' . implode(',', array_keys($json)),
                    $resp->getStatusCode() === 422 && isset($json['message'], $json['errors']['email'])];
            });

        $this->case($g, 'Pagination daftar reservasi', 'List memakai pagination (AGENTS.md bagian 8).',
            '1) GET /bookings?per_page=2', 'role=admin',
            'HTTP 200; data.data maksimal 2 baris; data.total & current_page terisi',
            function () {
                $resp  = $this->req('GET', '/api/v1/bookings?per_page=2', $this->admin);
                $page  = (array) $resp->json('data');
                $rows  = (array) ($page['data'] ?? []);

                return [$this->describe($resp) . ' | current_page=' . ($page['current_page'] ?? '-') . ', last_page=' . ($page['last_page'] ?? '-'),
                    $resp->getStatusCode() === 200 && count($rows) <= 2 && isset($page['total'], $page['current_page']) && $page['per_page'] == 2,
                    'Bentuk respons sesuai kontrak Sanctum/Laravel: { data: { data: [], total, per_page, ... } }.'];
            });

        $this->case($g, 'Pencarian reservasi lewat kode booking', 'Kasir membuka reservasi lama lewat kode.',
            '1) booking 2) GET /bookings?search=kode', 'kode dari booking QA',
            'HTTP 200 dan hasil pertama cocok dengan kode',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('GET', '/api/v1/bookings?search=' . urlencode($b->booking_code), $this->admin);
                $row  = $resp->json('data.0.booking_code') ?? $resp->json('data.data.0.booking_code');

                return [$this->describe($resp) . ' | kode ketemu=' . ($row ?: '-'), $resp->getStatusCode() === 200 && $row === $b->booking_code];
            });

        $this->case($g, 'Detail reservasi 404 untuk id tak dikenal', 'Resource tidak ada tidak boleh jadi 500.',
            '1) GET /bookings/999999', 'id fiktif',
            'HTTP 404',
            function () {
                $resp = $this->req('GET', '/api/v1/bookings/999999', $this->admin);

                return [$this->describe($resp), $resp->getStatusCode() === 404];
            });

        $this->case($g, 'Dashboard admin dan pegawai', 'Widget operasional hari ini tersedia untuk dua role.',
            '1) GET /dashboard admin 2) GET /dashboard pegawai', 'tanpa parameter',
            'Keduanya HTTP 200 dengan payload data',
            function () {
                $adm = $this->req('GET', '/api/v1/dashboard', $this->admin);
                $peg = $this->req('GET', '/api/v1/dashboard', $this->pegawai);

                return ['admin=' . $this->describe($adm) . ' | pegawai=' . $this->describe($peg),
                    $adm->getStatusCode() === 200 && $peg->getStatusCode() === 200 && $adm->json('data') !== null];
            });

        $this->case($g, 'Kode booking unik', 'Tidak boleh ada dua reservasi dengan kode sama.',
            '1) buat 2 reservasi 2) bandingkan kode', 'dua booking tamu berbeda',
            'Kode berbeda dan unik di database',
            function () {
                $a = $this->mkBooking()['booking']->booking_code;
                $b = $this->mkBooking()['booking']->booking_code;

                return ["kode A={$a}, kode B={$b}", $a !== $b && Booking::distinct()->count('booking_code') >= 2];
            });

        $this->case($g, 'Hapus kamar bersih memakai soft delete', 'Master data tidak hilang permanen (AGENTS.md 4.2).',
            '1) kamar tanpa histori 2) DELETE /rooms/{id} 3) cek deleted_at', 'kamar QA baru',
            'HTTP 200 dan baris masih ada dengan deleted_at terisi',
            function () {
                $n    = ++$this->roomSeq;
                $rt   = RoomType::orderBy('id')->first();
                $rm   = Room::create(['room_type_id' => $rt->id, 'room_number' => 'QX' . $n]);
                $resp = $this->req('DELETE', "/api/v1/rooms/{$rm->id}", $this->admin);
                $raw  = DB::table('rooms')->where('id', $rm->id)->first();

                return [$this->describe($resp) . ' | baris=' . ($raw ? 'masih ada, deleted_at=' . ($raw->deleted_at ?: 'NULL') : 'HILANG permanen'),
                    $resp->getStatusCode() === 200 && $raw && $raw->deleted_at !== null];
            });

        $this->case($g, 'Endpoint review tamu sesuai dokumen', 'AGENTS.md bagian 8 menjanjikan POST /bookings/{id}/review dan GET /reviews.',
            '1) POST /bookings/{id}/review 2) GET /reviews', 'rating=5, comment=Mantap',
            'HTTP 200/201 karena tercantum pada daftar endpoint',
            function () {
                $b    = $this->mkBooking()['booking'];
                $resp = $this->req('POST', "/api/v1/bookings/{$b->id}/review", $this->admin, ['rating' => 5, 'comment' => 'Mantap']);
                $list = $this->req('GET', '/api/v1/reviews', $this->admin);

                return ['POST=' . $this->describe($resp) . ' | GET /reviews=' . $list->getStatusCode(),
                    $resp->getStatusCode() < 300,
                    'Model Review sudah ada tapi route belum terdaftar di routes/api.php: perlu keputusan tim (tambah endpoint atau coret dari dokumen).'];
            });

        $this->case($g, 'Endpoint pembacaan audit log', 'Audit tercatat di DB tetapi belum ada endpoint bacanya.',
            '1) GET /api/v1/audit-logs sebagai admin', 'tanpa parameter',
            'Tidak ada di daftar endpoint AGENTS.md, jadi 404 diterima (gap kebutuhan UI)',
            function () {
                $resp = $this->req('GET', '/api/v1/audit-logs', $this->admin);

                return [$this->describe($resp) . ' | baris audit di DB=' . AuditLog::count(), $resp->getStatusCode() === 404,
                    'Audit hanya bisa dibaca lewat database/laporan; tidak ada layar audit di frontend.'];
            });

        $this->case($g, 'Uang disimpan sebagai decimal, bukan float', 'Kolom decimal(12,2) (AGENTS.md 4.3).',
            '1) booking tarif ganjil 2) cek booking_rooms', 'price=333333, 1 malam',
            'price_per_night & total maksimal 2 angka desimal',
            function () {
                $b  = $this->mkBooking(1, 333333)['booking'];
                $br = DB::table('booking_rooms')->where('booking_id', $b->id)->first();

                return ['price_per_night=' . $br->price_per_night . ', total_room=' . $br->total_price . ', booking total=' . $b->total_price,
                    preg_match('/^\d+(\.\d{1,2})?$/', (string) $br->price_per_night) === 1 && preg_match('/^\d+(\.\d{1,2})?$/', (string) $br->total_price) === 1];
            });

        $this->case($g, 'Kamar booked hari ini tampil sebagai penanda', 'Status booked hanya penanda visual; ketersediaan dihitung dari tanggal.',
            '1) booking confirmed hari ini 2) cek status kamar', 'sumber=walk_in',
            'Perubahan status tercatat di room_status_logs dan availability tetap benar',
            function () {
                $b    = $this->mkBooking(1, 250000);
                $this->req('POST', "/api/v1/bookings/{$b['booking']->id}/confirm", $this->admin, ['reason' => 'Uji penanda booked']);
                $avail = $this->req('GET', '/api/v1/rooms/availability?check_in=' . now()->format('Y-m-d') . '&check_out=' . now()->addDay()->format('Y-m-d'), $this->admin);
                $found = collect($avail->json('data') ?? [])->firstWhere('id', $b['room']->id);

                return ['confirm=' . $b['booking']->fresh()->status->value . ' | kamar muncul di availability=' . ($found ? 'YA' : 'tidak')
                    . ' | status kamar=' . $b['room']->fresh()->status->value,
                    $found === null && $b['booking']->fresh()->status === BookingStatus::Confirmed,
                    'Kamar dengan booking confirmed hari ini tidak boleh bisa dijual ulang.'];
            });
    }
}
