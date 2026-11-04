<?php

namespace App\Services;

use App\Exceptions\RoomUnavailableException;
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
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

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
                    throw new RoomUnavailableException("Kamar {$room->room_number} sedang dalam perbaikan (maintenance).");
                }

                // Cegah reservasi untuk hari ini jika kamar saat ini occupied
                if ($isToday && $statusVal === 'occupied') {
                    throw new RoomUnavailableException("Kamar {$room->room_number} sedang terisi (occupied) hari ini.");
                }

                if (!$this->isRoomAvailable($room->id, $data['check_in'], $data['check_out'])) {
                    throw new RoomUnavailableException("Kamar {$room->room_number} tidak tersedia untuk tanggal tersebut.");
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
                        'quantity' => $svc['qty'],
                        'total' => $total,
                    ];
                }
            }

            $grandSubtotal = $totalRoomPrice + $totalServicePrice;
            $discount = (float) ($data['discount_amount'] ?? 0);

            // Aturan bisnis no. 4 (AGENTS.md): diskon manual tidak boleh melebihi subtotal
            if ($discount > $grandSubtotal) {
                throw new Exception(
                    'Diskon tidak boleh melebihi subtotal tagihan (subtotal: Rp '
                    . number_format($grandSubtotal, 0, ',', '.') . ').'
                );
            }

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

            // 4. Simpan Relasi. ID booking_room dibutuhkan untuk menautkan penghuni ke kamarnya.
            $bookingRoomByRoomId = [];
            foreach ($bookingRoomsData as $brData) {
                $createdRoom = $booking->bookingRooms()->create($brData);
                $bookingRoomByRoomId[(int) $createdRoom->room_id] = $createdRoom;
            }

            foreach ($data['guests'] as $guest) {
                $bookingRoom = $this->resolveGuestRoom($guest, $bookingRoomByRoomId);

                $booking->bookingGuests()->create([
                    'booking_room_id' => $bookingRoom?->id,
                    'name' => $guest['name'],
                    'ktp' => $guest['ktp'] ?? null,
                    'phone' => $guest['phone'] ?? null,
                    'is_primary' => $guest['is_primary'],
                ]);
            }

            // Penempatan per kamar cuma diuji bila kasir memang memilih kamarnya,
            // supaya reservasi lama (tanpa pemetaan) tidak ikut tertolak.
            $this->assertRoomCapacity($booking, $bookingRoomByRoomId);

            foreach ($bookingServicesData as $bsData) {
                $booking->bookingServices()->create($bsData);
            }

            $booking->load(['bookingRooms.room', 'bookingGuests', 'bookingServices.service']);

            // Otomatis buat invoice draft saat booking baru dibuat
            $this->invoiceService->createForBooking($booking);

            if ($discount > 0) {
                // Diskon termasuk aksi penting yang wajib dicatat di audit log (bagian 6 butir 10)
                AuditService::log('booking.discount_applied', $booking, [], [
                    'discount_amount' => $discount,
                    'subtotal'        => $grandSubtotal,
                    'total_price'     => $grandTotal,
                ]);
            }

            return $booking;
        });
    }

    public function addServices(Booking $booking, array $services): Booking
    {
        return DB::transaction(function () use ($booking, $services) {
            if (in_array($booking->status->value, [BookingStatus::Cancelled->value, BookingStatus::CheckedOut->value], true)) {
                throw new Exception('Layanan tidak dapat ditambahkan pada reservasi yang sudah selesai atau dibatalkan.');
            }

            $serviceIds = collect($services)->pluck('service_id')->unique()->values();
            $serviceMap = Service::whereIn('id', $serviceIds)->get()->keyBy('id');

            foreach ($services as $item) {
                $service = $serviceMap->get($item['service_id']);
                if (!$service || !$service->is_active) {
                    throw new Exception('Layanan tambahan tidak valid atau tidak aktif.');
                }

                $quantity = (int) $item['qty'];
                $existing = $booking->bookingServices()->where('service_id', $service->id)->first();
                if ($existing) {
                    $quantity += (int) $existing->quantity;
                    $existing->update([
                        'quantity' => $quantity,
                        'total' => $service->price * $quantity,
                    ]);
                } else {
                    $booking->bookingServices()->create([
                        'service_id' => $service->id,
                        'price_at_transaction' => $service->price,
                        'quantity' => $quantity,
                        'total' => $service->price * $quantity,
                    ]);
                }
            }

            $invoice = $booking->invoice;
            if (!$invoice) {
                $invoice = $this->invoiceService->createForBooking($booking);
            } else {
                $this->invoiceService->recalculate($invoice);
            }

            return $booking->fresh(['bookingServices.service', 'invoice']);
        });
    }

    /**
     * Menautkan satu baris tamu ke baris kamar pada reservasi yang sama.
     *
     * Reservasi satu kamar ditautkan otomatis (penempatannya tidak ambigu, jadi kasir
     * tidak perlu memilih). Reservasi multi-kamar mewajibkan room_id diisi dan harus salah
     * satu kamar pada reservasi itu - tidak boleh diam-diam menebak.
     *
     * @param array<int, BookingRoom> $bookingRoomByRoomId
     */
    protected function resolveGuestRoom(array $guest, array $bookingRoomByRoomId): ?BookingRoom
    {
        if (!$bookingRoomByRoomId) {
            return null;
        }

        $roomId = (int) ($guest['room_id'] ?? 0);

        if ($roomId === 0) {
            if (count($bookingRoomByRoomId) === 1) {
                return reset($bookingRoomByRoomId);
            }

            throw new Exception('Reservasi multi-kamar: setiap penghuni harus ditempatkan ke kamar.');
        }

        if (!isset($bookingRoomByRoomId[$roomId])) {
            throw new Exception('Kamar yang ditugaskan untuk penghuni bukan bagian dari reservasi ini.');
        }

        return $bookingRoomByRoomId[$roomId];
    }

    /**
     * Kapasitas per kamar. Hanya menghitung penghuni yang benar-benar ditugaskan ke kamar itu,
     * jadi data yang belum ditempatkan tidak ikut menolak reservasi.
     *
     * @param array<int, BookingRoom> $bookingRoomByRoomId
     */
    protected function assertRoomCapacity(Booking $booking, array $bookingRoomByRoomId): void
    {
        if (!$bookingRoomByRoomId) {
            return;
        }

        $terbagi = $booking->bookingGuests()
            ->whereNotNull('booking_room_id')
            ->select('booking_room_id', DB::raw('COUNT(*) as total'))
            ->groupBy('booking_room_id')
            ->pluck('total', 'booking_room_id');

        foreach ($bookingRoomByRoomId as $bookingRoom) {
            $jumlah = (int) ($terbagi[$bookingRoom->id] ?? 0);
            $batas  = (int) ($bookingRoom->room?->roomType?->max_person ?? 0);

            if ($jumlah > 0 && $batas > 0 && $jumlah > $batas) {
                throw new Exception(sprintf(
                    'Kamar %s berkapasitas %d orang, tetapi %d penghuni ditugaskan ke kamar ini.',
                    $bookingRoom->room?->room_number ?? $bookingRoom->room_id,
                    $batas,
                    $jumlah
                ));
            }
        }
    }

    /**
     * Menyimpan penghuni yang baru diketahui di meja resepsionis.
     *
     * Nama yang sudah tercatat tidak diduplikasi - kalau baris itu membawa room_id, penempatan
     * kamarnya yang ditambal. Penambahan penghuni baru dibatasi sisa slot guest_count supaya
     * check-in tidak bisa menyisipkan tamu di luar reservasi. Semua perubahan dicatat di audit log.
     */
    protected function recordMissingGuests(Booking $booking, array $guests, int $userId): void
    {
        $guests = array_values(array_filter(
            is_array($guests) ? $guests : [],
            fn ($guest) => trim((string) ($guest['name'] ?? '')) !== ''
        ));

        if (!$guests) {
            return;
        }

        $dipesan = max(1, (int) $booking->guest_count);
        $sisaSlot = max(0, $dipesan - $booking->bookingGuests()->count());

        // room_id pada request mengacu ke kamar fisik, jadi dipetakan dulu ke booking_room
        $roomMap = $booking->bookingRooms()->get()
            ->mapWithKeys(fn (BookingRoom $bookingRoom) => [(int) $bookingRoom->room_id => $bookingRoom])
            ->all();

        $dikenal = $booking->bookingGuests()->get()
            ->keyBy(fn (BookingGuest $guest) => mb_strtolower(trim($guest->name)));

        $ditambahkan = 0;
        $ditempatkan = 0;

        foreach ($guests as $guest) {
            $name = trim((string) $guest['name']);
            $key  = mb_strtolower($name);
            $bookingRoom = $this->resolveGuestRoom($guest, $roomMap);

            if ($existing = $dikenal->get($key)) {
                if ($bookingRoom && !$existing->booking_room_id) {
                    $existing->booking_room_id = $bookingRoom->id;
                    $existing->save();
                    $ditempatkan++;
                }
                continue;
            }

            if ($ditambahkan >= $sisaSlot) {
                break;
            }

            $created = $booking->bookingGuests()->create([
                'booking_room_id' => $bookingRoom?->id,
                'name' => $name,
                'ktp' => $guest['ktp'] ?? null,
                'phone' => $guest['phone'] ?? null,
                'is_primary' => false,
            ]);

            $dikenal->put($key, $created);
            $ditambahkan++;
        }

        if ($ditambahkan > 0 || $ditempatkan > 0) {
            $this->assertRoomCapacity($booking, $roomMap);

            AuditService::log('booking.guests_completed', $booking, [], [
                'guests_added'  => $ditambahkan,
                'guests_placed' => $ditempatkan,
                'guest_count'   => $dipesan,
                'oleh_user'     => $userId,
            ]);
        }
    }

    /**
     * Proses Check-In.
     *
     * $guests opsional: penghuni yang belum tercatat saat reservasi bisa dilengkapi
     * di meja resepsionis dalam request yang sama (Model C: data penghuni harus lengkap
     * sebelum kamar dinyatakan terisi).
     */
    public function checkIn(Booking $booking, int $userId, array $guests = []): Booking
    {
        $statusValue = is_object($booking->status) ? $booking->status->value : $booking->status;

        // Best practice & AGENTS.md bagian 6 butir 5: hanya reservasi `confirmed` yang boleh
        // check-in. Pembayaran yang masih menunggu verifikasi harus diselesaikan lebih dulu
        // (verifikasi admin atau POST /bookings/{id}/confirm), bukan ditagih ulang di meja.
        if ($statusValue !== BookingStatus::Confirmed->value) {
            throw new Exception(
                $statusValue === BookingStatus::Pending->value
                    ? 'Reservasi belum terkonfirmasi. Lunasi/verifikasi pembayaran dulu, atau minta admin mengonfirmasi reservasi.'
                    : 'Reservasi tidak dalam status yang valid untuk Check-In.'
            );
        }

        return DB::transaction(function () use ($booking, $userId, $guests) {
            $this->recordMissingGuests($booking, $guests, $userId);

            // "Catat tamu yang menginap" ditegakkan: jumlah nama harus menyamai guest_count.
            $dipesan = max(1, (int) $booking->guest_count);
            $tercatat = $booking->bookingGuests()->count();
            if ($tercatat < $dipesan) {
                throw new Exception(
                    "Data penghuni belum lengkap: {$tercatat} dari {$dipesan} tamu tercatat. Lengkapi nama seluruh penghuni sebelum check-in."
                );
            }

            // Multi-kamar: semua penghuni sudah harus punya kamar saat kamar dinyatakan terisi,
            // supaya "siapa tidur di kamar mana" tidak menggantung setelah tamu masuk.
            if ($booking->bookingRooms()->count() > 1) {
                $belumDitempatkan = $booking->bookingGuests()->whereNull('booking_room_id')->count();
                if ($belumDitempatkan > 0) {
                    throw new Exception(
                        "Penempatan kamar belum lengkap: {$belumDitempatkan} penghuni belum ditugaskan ke kamar."
                    );
                }
            }

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
     * Konfirmasi manual reservasi: jalur resmi untuk booking Traveloka / kredit hotel
     * yang dananya tidak lewat sistem. Admin boleh melewati ambang DP minimum;
     * pegawai harus memenuhinya agar tidak bisa check-in tanpa uang masuk.
     */
    public function confirm(Booking $booking, int $userId, bool $isAdmin, ?string $reason = null): Booking
    {
        $statusValue = $booking->status instanceof BookingStatus
            ? $booking->status->value
            : $booking->status;

        if ($statusValue !== BookingStatus::Pending->value) {
            throw new Exception('Hanya reservasi berstatus pending yang bisa dikonfirmasi.');
        }

        $minDpPct = (float) (Setting::first()?->min_dp_percentage ?? 0);

        if ($minDpPct > 0 && !$isAdmin) {
            $threshold = round($booking->invoice_total * $minDpPct / 100, 2);

            if ($booking->paid_verified < $threshold) {
                throw new Exception(
                    'DP terverifikasi belum mencapai minimal '
                    . rtrim(rtrim(number_format($minDpPct, 2, '.', ''), '0'), '.') . '% tagihan. Butuh Rp '
                    . number_format($threshold, 0, ',', '.') . ', tercatat Rp '
                    . number_format($booking->paid_verified, 0, ',', '.') . '.'
                );
            }
        }

        $booking->update(['status' => BookingStatus::Confirmed->value]);

        AuditService::log('booking.confirmed', $booking, ['status' => $statusValue], [
            'status' => BookingStatus::Confirmed->value,
            'reason' => $reason,
            'admin_override' => $isAdmin && $minDpPct > 0 && $booking->paid_verified < round($booking->invoice_total * $minDpPct / 100, 2),
        ]);

        return $booking->fresh();
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
        $statusValue = $booking->status instanceof BookingStatus
            ? $booking->status->value
            : $booking->status;

        // Booking yang sudah menginap/selesai/batal tidak boleh dibatalkan lagi
        // (AGENTS.md bagian 5). Perbandingan dilakukan pada nilai enum, bukan objeknya.
        if (in_array($statusValue, [
            BookingStatus::CheckedIn->value,
            BookingStatus::CheckedOut->value,
            BookingStatus::Cancelled->value,
        ], true)) {
            throw new Exception("Reservasi berstatus {$statusValue} tidak bisa dibatalkan lagi.");
        }

        return DB::transaction(function () use ($booking, $reason, $userId, $statusValue) {
            $booking->status = BookingStatus::Cancelled->value;
            $booking->cancel_reason = $reason;
            $booking->cancelled_at = now();
            $booking->save();

            // Invoice aktif di-void agar tidak lagi dihitung sebagai piutang (bagian 5)
            $invoice = $booking->invoice;
            if ($invoice) {
                $invoice->update(['status' => InvoiceStatus::Void->value]);
            }

            // Membebaskan kamar jika ada yang sempet berubah status (misal tadinya 'booked')
            $rooms = $booking->rooms()->lockForUpdate()->get();
            foreach ($rooms as $room) {
                // Kolom status di-cast ke enum, jadi bandingkan nilainya (bukan objek vs string)
                $roomStatus = $room->status instanceof RoomStatus ? $room->status->value : $room->status;

                if ($roomStatus === RoomStatus::Booked->value) {
                    $room->status = RoomStatus::Available->value;
                    $room->save();

                    RoomStatusLog::create([
                        'room_id' => $room->id,
                        'from_status' => $roomStatus,
                        'to_status' => RoomStatus::Available->value,
                        'changed_by' => $userId,
                        'booking_id' => $booking->id,
                        'reason' => 'Booking dibatalkan',
                    ]);
                }
            }

            // Pembatalan wajib tercatat di audit log (bagian 6 butir 10)
            AuditService::log('booking.cancelled', $booking, ['status' => $statusValue], [
                'status'        => BookingStatus::Cancelled->value,
                'cancel_reason' => $reason,
            ]);

            return $booking;
        });
    }
}
