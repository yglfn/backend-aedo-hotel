<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\BookingRoom;
use App\Models\BookingGuest;
use App\Models\BookingService as BookingServiceModel;
use App\Models\Service;
use App\Models\RoomStatusLog;
use App\Models\HousekeepingTask;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RoomStatus;
use App\Enums\HousekeepingStatus;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class BookingService
{
    public function __construct(protected InvoiceService $invoiceService) {}

    /**
     * Mengecek ketersediaan kamar pada rentang tanggal tertentu.
     */
    public function isRoomAvailable(int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): bool
    {
        $query = BookingRoom::where('room_id', $roomId)
            ->where(function ($q) use ($checkIn, $checkOut) {
                // Irisan waktu: check_in baru < check_out lama AND check_out baru > check_in lama
                $q->where('check_in', '<', $checkOut)
                  ->where('check_out', '>', $checkIn);
            })
            ->whereHas('booking', function ($q) {
                // Jangan hiraukan booking yang sudah di-cancel atau checked_out
                $q->whereNotIn('status', [BookingStatus::Cancelled->value, BookingStatus::CheckedOut->value]);
            });

        if ($excludeBookingId) {
            $query->where('booking_id', '!=', $excludeBookingId);
        }

        return !$query->exists();
    }

    /**
     * Membuat reservasi baru
     */
    public function createBooking(array $data, int $createdByUserId): Booking
    {
        return DB::transaction(function () use ($data, $createdByUserId) {
            $checkIn = Carbon::parse($data['check_in']);
            $checkOut = Carbon::parse($data['check_out']);
            $nights = $checkIn->diffInDays($checkOut);

            if ($nights < 1) {
                throw new Exception("Minimal menginap adalah 1 malam.");
            }

            // 1. Validasi Availability & Lock Kamar
            $roomIds = $data['rooms'];
            $lockedRooms = Room::with('roomType')->whereIn('id', $roomIds)->lockForUpdate()->get();

            if ($lockedRooms->count() !== count($roomIds)) {
                throw new Exception("Satu atau lebih kamar tidak ditemukan.");
            }

            $totalRoomPrice = 0;
            $maxPersonCapacity = 0;
            $bookingRoomsData = [];

            $isToday = $checkIn->isToday();

            foreach ($lockedRooms as $room) {
                // Cegah reservasi jika kamar dalam maintenance
                $statusVal = is_object($room->status) ? $room->status->value : $room->status;
                if ($statusVal === 'maintenance') {
                    throw new Exception("Kamar {$room->room_number} sedang dalam perbaikan (maintenance).");
                }

                // Cegah reservasi untuk hari ini jika kamar saat ini occupied
                if ($isToday && $statusVal === 'occupied') {
                    throw new Exception("Kamar {$room->room_number} sedang terisi (occupied) hari ini.");
                }

                if (!$this->isRoomAvailable($room->id, $data['check_in'], $data['check_out'])) {
                    throw new Exception("Kamar {$room->room_number} tidak tersedia untuk tanggal tersebut.");
                }

                $pricePerNight = $room->roomType->price;
                $totalPrice = $pricePerNight * $nights;
                $totalRoomPrice += $totalPrice;
                $maxPersonCapacity += $room->roomType->max_person;

                $bookingRoomsData[] = [
                    'room_id' => $room->id,
                    'check_in' => $data['check_in'],
                    'check_out' => $data['check_out'],
                    'price_per_night' => $pricePerNight,
                    'nights' => $nights,
                    'total_price' => $totalPrice,
                ];
            }

            if ($data['guest_count'] > $maxPersonCapacity) {
                throw new Exception("Jumlah tamu melebihi kapasitas kamar yang dipilih (Maksimal: {$maxPersonCapacity} orang).");
            }

            // 2. Hitung Services (jika ada)
            $totalServicePrice = 0;
            $bookingServicesData = [];
            if (!empty($data['services'])) {
                $serviceIds = collect($data['services'])->pluck('service_id')->toArray();
                $services = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

                foreach ($data['services'] as $svc) {
                    $service = $services->get($svc['service_id']);
                    if (!$service || !$service->is_active) {
                        throw new Exception("Layanan tambahan tidak valid atau tidak aktif.");
                    }

                    $total = $service->price * $svc['qty'];
                    $totalServicePrice += $total;

                    $bookingServicesData[] = [
                        'service_id' => $service->id,
                        'price_at_transaction' => $service->price,
                        'qty' => $svc['qty'],
                        'total' => $total,
                    ];
                }
            }

            $grandSubtotal = $totalRoomPrice + $totalServicePrice;
            $discount = (float) ($data['discount_amount'] ?? 0);
            $setting = Setting::first();
            $taxPct = (float) ($setting?->tax_percentage ?? 0);
            $taxAmount = ($grandSubtotal - $discount) * $taxPct / 100;
            $grandTotal = $grandSubtotal - $discount + $taxAmount;

            // 3. Simpan Header Booking
            $booking = Booking::create([
                'booking_code' => 'BK-' . strtoupper(Str::random(8)),
                'customer_id' => $data['customer_id'],
                'created_by' => $createdByUserId,
                'source' => $data['source'],
                'guest_count' => $data['guest_count'],
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'status' => BookingStatus::Pending->value, // Akan jadi confirmed saat dibayar
                'discount_amount' => $discount,
                'total_price' => $grandTotal,
                'notes' => $data['notes'] ?? null,
            ]);

            // 4. Simpan Relasi
            foreach ($bookingRoomsData as $brData) {
                $booking->bookingRooms()->create($brData);
            }

            foreach ($data['guests'] as $guest) {
                $booking->bookingGuests()->create([
                    'name' => $guest['name'],
                    'ktp' => $guest['ktp'] ?? null,
                    'is_primary' => $guest['is_primary'],
                ]);
            }

            foreach ($bookingServicesData as $bsData) {
                $booking->bookingServices()->create($bsData);
            }

            $booking->load(['bookingRooms.room', 'bookingGuests', 'bookingServices.service']);

            // Otomatis buat invoice draft saat booking baru dibuat
            $this->invoiceService->createForBooking($booking);

            return $booking;
        });
    }

    /**
     * Proses Check-In
     */
    public function checkIn(Booking $booking, int $userId): Booking
    {
        $statusValue = is_object($booking->status) ? $booking->status->value : $booking->status;
        if (!in_array($statusValue, [BookingStatus::Confirmed->value, BookingStatus::Pending->value])) {
            throw new Exception("Reservasi tidak dalam status yang valid untuk Check-In.");
        }

        return DB::transaction(function () use ($booking, $userId) {
            $booking->status = BookingStatus::CheckedIn->value;
            $booking->save();

            // Ubah status kamar & catat log
            $rooms = $booking->rooms()->lockForUpdate()->get();
            foreach ($rooms as $room) {
                $oldStatus = $room->status;
                $room->status = RoomStatus::Occupied->value;
                $room->save();

                RoomStatusLog::create([
                    'room_id' => $room->id,
                    'from_status' => $oldStatus,
                    'to_status' => RoomStatus::Occupied->value,
                    'changed_by' => $userId,
                    'booking_id' => $booking->id,
                    'reason' => 'Tamu Check-In',
                ]);
            }

            return $booking;
        });
    }

    /**
     * Proses Check-Out
     */
    public function checkOut(Booking $booking, int $userId): Booking
    {
        $statusValue = is_object($booking->status) ? $booking->status->value : $booking->status;
        if ($statusValue !== BookingStatus::CheckedOut->value && $statusValue !== BookingStatus::CheckedIn->value) {
            throw new Exception("Hanya reservasi berstatus Checked-In yang bisa Check-Out.");
        }

        // Validasi invoice harus lunas sebelum check-out (Aturan bisnis no. 6 AGENTS.md)
        $invoice = $booking->invoice;
        if ($invoice) {
            $invStatus = is_object($invoice->status) ? $invoice->status->value : $invoice->status;
            if ($invStatus !== InvoiceStatus::Paid->value) {
                $totalPaid = $booking->getTotalPaidAttribute();
                $remaining = (float) $invoice->total - $totalPaid;
                if ($remaining > 0) {
                    throw new Exception(
                        'Tagihan belum lunas. Sisa pembayaran: Rp ' . number_format($remaining, 0, ',', '.')
                    );
                }
            }
        }

        return DB::transaction(function () use ($booking, $userId) {
            $booking->status = BookingStatus::CheckedOut->value;
            $booking->save();

            // Ubah status kamar menjadi cleaning
            $rooms = $booking->rooms()->lockForUpdate()->get();
            foreach ($rooms as $room) {
                $oldStatus = $room->status;
                $room->status = RoomStatus::Cleaning->value;
                $room->save();

                RoomStatusLog::create([
                    'room_id' => $room->id,
                    'from_status' => $oldStatus,
                    'to_status' => RoomStatus::Cleaning->value,
                    'changed_by' => $userId,
                    'booking_id' => $booking->id,
                    'reason' => 'Tamu Check-Out (perlu dibersihkan)',
                ]);

                // Buat task housekeeping otomatis
                HousekeepingTask::create([
                    'room_id' => $room->id,
                    'status' => HousekeepingStatus::Pending->value,
                    'notes' => 'Pembersihan rutin paska check-out (Booking: ' . $booking->booking_code . ')',
                    'created_by' => $userId,
                ]);
            }

            return $booking;
        });
    }

    /**
     * Proses Cancel
     */
    public function cancel(Booking $booking, string $reason, int $userId): Booking
    {
        if (in_array($booking->status, [BookingStatus::CheckedIn->value, BookingStatus::CheckedOut->value, BookingStatus::Cancelled->value])) {
            throw new Exception("Reservasi tidak bisa dibatalkan pada status saat ini.");
        }

        return DB::transaction(function () use ($booking, $reason, $userId) {
            $booking->status = BookingStatus::Cancelled->value;
            $booking->cancel_reason = $reason;
            $booking->cancelled_at = now();
            $booking->save();

            // Membebaskan kamar jika ada yang sempet berubah status (misal tadinya 'booked')
            $rooms = $booking->rooms()->lockForUpdate()->get();
            foreach ($rooms as $room) {
                if ($room->status === RoomStatus::Booked->value) {
                    $oldStatus = $room->status;
                    $room->status = RoomStatus::Available->value;
                    $room->save();

                    RoomStatusLog::create([
                        'room_id' => $room->id,
                        'from_status' => $oldStatus,
                        'to_status' => RoomStatus::Available->value,
                        'changed_by' => $userId,
                        'booking_id' => $booking->id,
                        'reason' => 'Booking dibatalkan',
                    ]);
                }
            }

            return $booking;
        });
    }
}
