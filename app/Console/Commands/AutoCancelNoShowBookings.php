<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RoomStatus;
use App\Models\Booking;
use App\Models\RoomStatusLog;
use App\Services\AuditService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pembatalan otomatis reservasi no-show.
 *
 * Dijalankan setiap hari pada jam yang dikonfigurasi di console.php.
 * Kriteria no-show: booking berstatus `pending` atau `confirmed` dengan
 * tanggal check_in sudah lewat (< hari ini) dan tamu tidak pernah check-in.
 *
 * Catatan desain (AGENTS.md bagian 5 & 6):
 * - Hanya status `pending` dan `confirmed` yang bisa dibatalkan.
 * - Booking `checked_in`, `checked_out`, dan `cancelled` tidak disentuh.
 * - Setiap pembatalan dicatat di audit_logs dengan user_id = null (sistem).
 * - Invoice aktif di-void, kamar yang berstatus `booked` dikembalikan ke `available`.
 */
class AutoCancelNoShowBookings extends Command
{
    protected $signature = 'bookings:auto-cancel-no-show
                            {--dry-run : Tampilkan saja booking yang akan dibatalkan tanpa benar-benar membatalkan}
                            {--grace-hours=2 : Toleransi jam setelah tengah malam sebelum dianggap no-show (default: 2)}';

    protected $description = 'Batalkan otomatis reservasi yang check-in-nya sudah lewat dan tamu tidak pernah datang (no-show).';

    public function handle(NotificationService $notificationService): int
    {
        $graceHours = (int) $this->option('grace-hours');
        $isDryRun   = (bool) $this->option('dry-run');

        // Batas waktu: kemarin pukul 23:59:59 dikurangi toleransi grace hours.
        // Misal grace-hours=2 → booking check_in=kemarin baru dianggap no-show
        // setelah hari ini pukul 02:00.
        $cutoff = Carbon::today()->subHours($graceHours < 0 ? 0 : $graceHours);

        $this->info("Mencari reservasi no-show (check_in < {$cutoff->toDateTimeString()}) ...");

        // Ambil semua booking yang belum check-in dan tanggal check-in sudah lewat.
        // Gunakan lockForUpdate di dalam transaction saat memproses tiap baris.
        $bookings = Booking::with(['rooms', 'invoice'])
            ->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Confirmed->value])
            ->whereDate('check_in', '<', $cutoff->toDateString())
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('Tidak ada reservasi no-show yang ditemukan.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$bookings->count()} reservasi no-show.");

        if ($isDryRun) {
            $this->table(
                ['ID', 'Kode', 'Status', 'Check-In', 'Tamu'],
                $bookings->map(fn (Booking $b) => [
                    $b->id,
                    $b->booking_code,
                    $b->status->value,
                    $b->check_in->toDateString(),
                    $b->customer?->name ?? '-',
                ])->toArray()
            );
            $this->warn('[dry-run] Tidak ada yang benar-benar dibatalkan.');
            return self::SUCCESS;
        }

        $cancelled = 0;
        $failed    = 0;

        foreach ($bookings as $booking) {
            try {
                DB::transaction(function () use ($booking) {
                    // Lock baris agar tidak ada race condition dengan proses lain
                    $fresh = Booking::whereKey($booking->getKey())->lockForUpdate()->first();

                    // Periksa lagi statusnya di dalam transaction; bisa saja sudah berubah
                    // (mis. pegawai baru saja check-in) sejak kita mengambil daftar di atas.
                    $statusVal = $fresh->status instanceof BookingStatus
                        ? $fresh->status->value
                        : $fresh->status;

                    if (!in_array($statusVal, [BookingStatus::Pending->value, BookingStatus::Confirmed->value], true)) {
                        return; // Sudah berubah, lewati
                    }

                    $fresh->status      = BookingStatus::Cancelled->value;
                    $fresh->cancel_reason = 'Pembatalan otomatis: tamu tidak datang (no-show) pada tanggal check-in.';
                    $fresh->cancelled_at  = now();
                    $fresh->save();

                    // Void invoice aktif
                    $invoice = $fresh->invoice;
                    if ($invoice) {
                        $invoice->update(['status' => InvoiceStatus::Void->value]);
                    }

                    // Bebaskan kamar yang masih berstatus `booked`
                    $rooms = $fresh->rooms()->lockForUpdate()->get();
                    foreach ($rooms as $room) {
                        $roomStatus = $room->status instanceof RoomStatus
                            ? $room->status->value
                            : $room->status;

                        if ($roomStatus === RoomStatus::Booked->value) {
                            $room->status = RoomStatus::Available->value;
                            $room->save();

                            RoomStatusLog::create([
                                'room_id'     => $room->id,
                                'from_status' => $roomStatus,
                                'to_status'   => RoomStatus::Available->value,
                                'changed_by'  => null,  // null = diubah sistem
                                'booking_id'  => $fresh->id,
                                'reason'      => 'Auto-cancel no-show: kamar dibebaskan oleh sistem.',
                            ]);
                        }
                    }

                    // Catat di audit log; user_id = null menandakan aksi oleh sistem (cron)
                    AuditService::log(
                        'booking.auto_cancelled_no_show',
                        $fresh,
                        ['status' => $statusVal],
                        [
                            'status'        => BookingStatus::Cancelled->value,
                            'cancel_reason' => $fresh->cancel_reason,
                            'check_in'      => $fresh->check_in->toDateString(),
                            'triggered_by'  => 'scheduler',
                        ]
                    );
                });

                $cancelled++;
                $this->line("  ✓ Dibatalkan: [{$booking->booking_code}] {$booking->customer?->name} (check-in: {$booking->check_in->toDateString()})");

            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ✗ Gagal batalkan [{$booking->booking_code}]: {$e->getMessage()}");
                Log::error('AutoCancelNoShowBookings: gagal membatalkan booking', [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        // Kirim notifikasi ringkasan ke semua user aktif jika ada yang berhasil dibatalkan
        if ($cancelled > 0) {
            try {
                $notificationService->noShowAutoCancelled($cancelled);
            } catch (\Throwable $e) {
                // Jangan gagalkan command hanya karena notifikasi error
                Log::warning('AutoCancelNoShowBookings: gagal kirim notifikasi', ['error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("Selesai. Dibatalkan: {$cancelled}, Gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
