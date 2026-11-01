<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\RoomStatus;
use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Exceptions\InvalidFormatException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    /** Judul dokumen per jenis laporan; memakai Bahasa Indonesia, bukan nama route. */
    protected const DOC_TITLE = [
        'summary' => 'Ringkasan Laporan',
        'occupancy' => 'Laporan Okupansi',
        'revenue' => 'Laporan Pendapatan',
        'reservations' => 'Laporan Reservasi',
        'transactions' => 'Laporan Transaksi Pembayaran',
        'payment-methods' => 'Laporan Metode Pembayaran',
        'shifts' => 'Laporan Shift Pegawai',
        'sources' => 'Laporan Sumber Reservasi',
        'housekeeping' => 'Laporan Housekeeping',
    ];

    // ---------------------------------------------------------------
    // Helper: kembalikan data, PDF, atau Excel berdasarkan ?format=
    // ---------------------------------------------------------------
    protected function respond(string $type, array $data, Request $request)
    {
        $format = $request->query('format', 'json');

        return match ($format) {
            'pdf'  => $this->asPdf($type, $data),
            'xlsx' => $this->asExcel($type, $data),
            default => response()->json(['data' => $data]),
        };
    }

    protected function asPdf(string $type, array $data)
    {
        $view = "reports.{$type}";

        // Fallback ke view generik jika view spesifik tidak ada
        if (!view()->exists($view)) {
            $view = 'reports.generic';
        }

        $pdf = Pdf::loadView($view, ['report' => $data])
            ->setPaper('a4', 'landscape');

        return $pdf->download("laporan-{$type}-" . now()->format('Ymd') . '.pdf');
    }

    protected function asExcel(string $type, array $data)
    {
        [$rows, $headings] = $this->flattenForExcel($type, $data);

        $period = ($data['period']['from'] ?? '') . ' s/d ' . ($data['period']['to'] ?? '');
        $title = (self::DOC_TITLE[$type] ?? 'Laporan ' . ucfirst($type)) . ' (' . $period . ')';

        return Excel::download(
            new ReportExport($rows, $headings, $title),
            "laporan-{$type}-" . now()->format('Ymd') . '.xlsx'
        );
    }

    /**
     * Ubah data laporan menjadi array baris untuk Excel.
     */
    protected function flattenForExcel(string $type, array $data): array
    {
        return match ($type) {
            'summary' => [
                $this->summaryRows($data),
                ['Bagian', 'Ukuran', 'Nilai'],
            ],
            'occupancy' => [
                collect($data['per_room'])->map(fn($r) => [
                    $r->room_number,
                    $r->room_type ?? '-',
                    $r->floor ?? '-',
                    $r->occupied_nights,
                    $r->room_rate . '%',
                ])->toArray(),
                ['No. Kamar', 'Tipe Kamar', 'Lantai', 'Malam Terisi', '% Okupansi Kamar'],
            ],
            'revenue' => [
                collect($data['daily'])->map(fn($r) => [
                    $r->date,
                    $r->revenue,
                    $r->transactions,
                ])->toArray(),
                ['Tanggal', 'Pendapatan (Rp)', 'Jumlah Transaksi'],
            ],
            'reservations' => [
                collect($data['bookings'])->map(fn($b) => [
                    $b->booking_code,
                    $b->customer->name ?? '-',
                    $b->check_in?->toDateString(),
                    $b->check_out?->toDateString(),
                    $b->bookingRooms->pluck('room.room_number')->filter()->implode(', ') ?: '-',
                    $b->guest_count,
                    $b->status->value ?? $b->status,
                    (float) $b->total_price,
                    (float) $b->paid_verified,
                    (float) $b->remaining_due,
                ])->toArray(),
                ['Kode Booking', 'Tamu', 'Check-In', 'Check-Out', 'Kamar', 'Jumlah Tamu', 'Status', 'Total (Rp)', 'Terbayar (Rp)', 'Sisa (Rp)'],
            ],
            'transactions' => [
                collect($data['payments'])->map(fn($p) => [
                    $p->id,
                    $p->booking->booking_code ?? '-',
                    $p->booking->customer->name ?? '-',
                    $p->method->value ?? $p->method,
                    $p->type->value  ?? $p->type,
                    $p->status->value ?? $p->status,
                    (float) $p->amount,
                    $p->paid_at,
                    $p->createdBy->name ?? '-',
                    $p->verifiedBy->name ?? '-',
                ])->toArray(),
                ['ID', 'Kode Booking', 'Tamu', 'Metode', 'Tipe', 'Status', 'Nominal (Rp)', 'Tanggal', 'Dicatat Oleh', 'Diverifikasi Oleh'],
            ],
            'payment-methods' => [
                collect($data['data'])->map(fn($r) => [
                    $r->label ?? $r->method,
                    $r->count,
                    $r->total,
                    $r->share_percent . '%',
                    $r->average,
                ])->toArray(),
                ['Metode', 'Jumlah Transaksi', 'Total (Rp)', 'Porsi Nominal', 'Rata-rata per Transaksi (Rp)'],
            ],
            'shifts' => [
                collect($data['shifts'])->map(fn($s) => [
                    $s->pegawai,
                    $s->started_at,
                    $s->ended_at ?? '-',
                    $s->status,
                    $s->duration_hours ?? '-',
                    $s->total_transactions,
                    $s->total_collected,
                ])->toArray(),
                ['Pegawai', 'Mulai', 'Selesai', 'Status', 'Durasi (Jam)', 'Jumlah Transaksi', 'Total Dikumpulkan (Rp)'],
            ],
            'sources' => [
                collect($data['data'])->map(fn($r) => [
                    $r->label ?? $r->source,
                    $r->count,
                    $r->total_revenue,
                    $r->share_percent . '%',
                    $r->average_value,
                ])->toArray(),
                ['Sumber', 'Jumlah Reservasi', 'Total Pendapatan (Rp)', 'Porsi Reservasi', 'Rata-rata per Reservasi (Rp)'],
            ],
            'housekeeping' => [
                collect($data['tasks'])->map(fn($t) => [
                    $t->room->room_number ?? '-',
                    $t->status->value ?? $t->status,
                    $t->assignedTo->name ?? '-',
                    $t->notes ?? '-',
                    $t->completed_at ?? '-',
                ])->toArray(),
                ['Kamar', 'Status', 'Ditugaskan Ke', 'Catatan', 'Selesai Pada'],
            ],
            default => [[], []],
        };
    }

    /**
     * Ringkasan diubah jadi daftar baris [Bagian, Ukuran, Nilai] supaya satu sheet Excel
     * memuat seluruh angka penting, termasuk tren harian dan rincian per metode/sumber.
     */
    protected function summaryRows(array $data): array
    {
        $revenue = $data['revenue'];
        $occupancy = $data['occupancy'];
        $reservations = $data['reservations'];
        $shifts = $data['shifts'];
        $housekeeping = $data['housekeeping'];
        $rooms = $data['rooms'];
        $highlights = $data['highlights'];

        $rows = [
            ['Periode', 'Rentang tanggal', $data['period']['from'] . ' s/d ' . $data['period']['to']],
            ['Periode', 'Jumlah hari', $data['days']],
            ['Periode', 'Pembanding', $data['previous_period']['from'] . ' s/d ' . $data['previous_period']['to']],

            ['Pendapatan', 'Total terverifikasi (Rp)', $revenue['total_revenue']],
            ['Pendapatan', 'Transaksi terverifikasi', $revenue['total_transactions']],
            ['Pendapatan', 'Rata-rata per hari (Rp)', $revenue['average_per_day']],
            ['Pendapatan', 'Tunai (Rp)', $revenue['cash_revenue']],
            ['Pendapatan', 'Transfer (Rp)', $revenue['transfer_revenue']],
            ['Pendapatan', 'QRIS (Rp)', $revenue['qris_revenue']],
            ['Pendapatan', 'Refund terverifikasi (Rp)', $revenue['refund_total']],
            ['Pendapatan', 'Menunggu verifikasi (transaksi)', $revenue['pending_count']],
            ['Pendapatan', 'Menunggu verifikasi (Rp)', $revenue['pending_amount']],
            ['Pendapatan', 'Periode sebelumnya (Rp)', $revenue['previous_revenue']],
            ['Pendapatan', 'Perubahan (%)', $revenue['change_percent'] ?? 'belum ada pembanding'],

            ['Okupansi', 'Tingkat okupansi (%)', $occupancy['occupancy_rate']],
            ['Okupansi', 'Malam terisi', $occupancy['occupied_nights']],
            ['Okupansi', 'Kamar-malam tersedia', $occupancy['total_room_nights']],
            ['Okupansi', 'Kamar tidak pernah terisi', $occupancy['idle_rooms']],
            ['Okupansi', 'Periode sebelumnya (%)', $occupancy['previous_rate']],
            ['Okupansi', 'Perubahan (poin)', $occupancy['change_points']],

            ['Reservasi', 'Jumlah reservasi', $reservations['total']],
            ['Reservasi', 'Nilai reservasi (Rp)', $reservations['total_value']],
            ['Reservasi', 'Periode sebelumnya (jumlah)', $reservations['previous_total']],
            ['Reservasi', 'Perubahan (%)', $reservations['change_percent'] ?? 'belum ada pembanding'],
        ];

        foreach ($reservations['by_status'] as $status => $row) {
            $rows[] = ['Reservasi per status', BookingStatus::tryFrom($status)?->label() ?? $status, $row['count']];
        }

        foreach ($data['payment_mix'] as $method) {
            $rows[] = ['Metode bayar', $method->label, (float) $method->total];
        }

        foreach ($data['booking_sources'] as $source) {
            $rows[] = ['Sumber reservasi', $source->label, (int) $source->count];
        }

        $rows[] = ['Shift', 'Jumlah shift', $shifts['total_shifts']];
        $rows[] = ['Shift', 'Transaksi tercatat', $shifts['total_transactions']];
        $rows[] = ['Shift', 'Kas terverifikasi (Rp)', $shifts['total_collected']];
        $rows[] = ['Shift', 'Rata-rata per shift (Rp)', $shifts['average_collected']];

        $rows[] = ['Housekeeping', 'Total tugas', $housekeeping['total']];
        $rows[] = ['Housekeeping', 'Menunggu', $housekeeping['pending']];
        $rows[] = ['Housekeeping', 'Sedang dikerjakan', $housekeeping['in_progress']];
        $rows[] = ['Housekeeping', 'Selesai', $housekeeping['completed']];
        $rows[] = ['Housekeeping', 'Tingkat penyelesaian (%)', $housekeeping['completion_rate']];
        $rows[] = ['Housekeeping', 'Rata-rata durasi (jam)', $housekeeping['average_hours'] ?? '-'];

        foreach (RoomStatus::cases() as $case) {
            $rows[] = ['Kamar saat ini', $case->label(), $rooms[$case->value] ?? 0];
        }
        $rows[] = ['Kamar saat ini', 'Total kamar', $rooms['total']];

        if ($highlights['best_day']) {
            $rows[] = ['Sorotan', 'Pendapatan tertinggi (' . $highlights['best_day']['date'] . ')', (float) $highlights['best_day']['revenue']];
        }

        if ($highlights['best_room']) {
            $rows[] = ['Sorotan', 'Kamar paling banyak dipakai (' . $highlights['best_room']['room_number'] . ')', $highlights['best_room']['occupied_nights']];
        }

        if ($highlights['top_source']) {
            $label = BookingSource::tryFrom($highlights['top_source']['source'])?->label()
                ?? $highlights['top_source']['source'];
            $rows[] = ['Sorotan', 'Sumber reservasi terbanyak (' . $label . ')', $highlights['top_source']['count']];
        }

        foreach ($data['revenue_trend'] as $day) {
            $rows[] = ['Tren pendapatan', $day->date, (float) $day->revenue];
        }

        return $rows;
    }

    // ---------------------------------------------------------------
    // Endpoint per jenis laporan
    // ---------------------------------------------------------------

    /**
     * Periode dari query string. Tanggal yang tidak valid harus dijawab 422, bukan 500,
     * karena periode kini ikut tersimpan di URL halaman laporan.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function period(Request $request): array
    {
        $range = [];

        foreach (['from', 'to'] as $key) {
            $value = $request->query($key);

            if ($value === null || $value === '') {
                $range[$key] = null;
                continue;
            }

            try {
                $range[$key] = Carbon::parse((string) $value)->toDateString();
            } catch (InvalidFormatException) {
                abort(422, 'Format tanggal tidak valid. Gunakan YYYY-MM-DD.');
            }
        }

        if ($range['from'] && $range['to'] && $range['from'] > $range['to']) {
            abort(422, 'Tanggal awal tidak boleh lebih besar dari tanggal akhir.');
        }

        return [$range['from'], $range['to']];
    }

    /**
     * Ringkasan semua laporan — satu-satunya laporan yang ditampilkan di layar
     * Pusat Laporan, dan bisa diunduh sebagai PDF/Excel.
     */
    public function summary(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('summary', $this->reportService->summary($from, $to), $request);
    }

    public function occupancy(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('occupancy', $this->reportService->occupancy($from, $to), $request);
    }

    public function revenue(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('revenue', $this->reportService->revenue($from, $to), $request);
    }

    public function reservations(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('reservations', $this->reportService->reservations($from, $to), $request);
    }

    public function transactions(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('transactions', $this->reportService->transactions($from, $to), $request);
    }

    public function paymentMethods(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('payment-methods', $this->reportService->paymentMethods($from, $to), $request);
    }

    public function shifts(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('shifts', $this->reportService->shifts($from, $to), $request);
    }

    public function sources(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('sources', $this->reportService->sources($from, $to), $request);
    }

    public function housekeeping(Request $request)
    {
        [$from, $to] = $this->period($request);

        return $this->respond('housekeeping', $this->reportService->housekeeping($from, $to), $request);
    }
}
