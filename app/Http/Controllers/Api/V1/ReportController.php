<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

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

        return Excel::download(
            new ReportExport($rows, $headings, "Laporan " . ucfirst($type)),
            "laporan-{$type}-" . now()->format('Ymd') . '.xlsx'
        );
    }

    /**
     * Ubah data laporan menjadi array baris untuk Excel.
     */
    protected function flattenForExcel(string $type, array $data): array
    {
        return match ($type) {
            'occupancy' => [
                collect($data['per_room'])->map(fn($r) => [
                    $r->room_number,
                    $r->occupied_nights,
                ])->toArray(),
                ['No. Kamar', 'Malam Terisi'],
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
                    $b->check_in,
                    $b->check_out,
                    $b->status->value ?? $b->status,
                    $b->total_price,
                ])->toArray(),
                ['Kode Booking', 'Tamu', 'Check-In', 'Check-Out', 'Status', 'Total (Rp)'],
            ],
            'transactions' => [
                collect($data['payments'])->map(fn($p) => [
                    $p->id,
                    $p->booking->booking_code ?? '-',
                    $p->method->value ?? $p->method,
                    $p->type->value  ?? $p->type,
                    $p->status->value ?? $p->status,
                    $p->amount,
                    $p->paid_at,
                    $p->createdBy->name ?? '-',
                ])->toArray(),
                ['ID', 'Kode Booking', 'Metode', 'Tipe', 'Status', 'Nominal (Rp)', 'Tanggal', 'Dicatat Oleh'],
            ],
            'payment-methods' => [
                collect($data['data'])->map(fn($r) => [
                    $r->method,
                    $r->count,
                    $r->total,
                ])->toArray(),
                ['Metode', 'Jumlah Transaksi', 'Total (Rp)'],
            ],
            'shifts' => [
                collect($data['shifts'])->map(fn($s) => [
                    $s->pegawai,
                    $s->started_at,
                    $s->ended_at ?? '-',
                    $s->status,
                    $s->total_transactions,
                    $s->total_collected,
                ])->toArray(),
                ['Pegawai', 'Mulai', 'Selesai', 'Status', 'Jumlah Transaksi', 'Total Dikumpulkan (Rp)'],
            ],
            'sources' => [
                collect($data['data'])->map(fn($r) => [
                    $r->source,
                    $r->count,
                    $r->total_revenue,
                ])->toArray(),
                ['Sumber', 'Jumlah Reservasi', 'Total Pendapatan (Rp)'],
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

    // ---------------------------------------------------------------
    // Endpoint per jenis laporan
    // ---------------------------------------------------------------
    public function occupancy(Request $request)
    {
        $data = $this->reportService->occupancy($request->from, $request->to);
        return $this->respond('occupancy', $data, $request);
    }

    public function revenue(Request $request)
    {
        $data = $this->reportService->revenue($request->from, $request->to);
        return $this->respond('revenue', $data, $request);
    }

    public function reservations(Request $request)
    {
        $data = $this->reportService->reservations($request->from, $request->to);
        return $this->respond('reservations', $data, $request);
    }

    public function transactions(Request $request)
    {
        $data = $this->reportService->transactions($request->from, $request->to);
        return $this->respond('transactions', $data, $request);
    }

    public function paymentMethods(Request $request)
    {
        $data = $this->reportService->paymentMethods($request->from, $request->to);
        return $this->respond('payment-methods', $data, $request);
    }

    public function shifts(Request $request)
    {
        $data = $this->reportService->shifts($request->from, $request->to);
        return $this->respond('shifts', $data, $request);
    }

    public function sources(Request $request)
    {
        $data = $this->reportService->sources($request->from, $request->to);
        return $this->respond('sources', $data, $request);
    }

    public function housekeeping(Request $request)
    {
        $data = $this->reportService->housekeeping($request->from, $request->to);
        return $this->respond('housekeeping', $data, $request);
    }
}
