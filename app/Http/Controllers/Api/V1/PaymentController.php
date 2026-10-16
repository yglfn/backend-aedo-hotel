<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Exception;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * Daftar pembayaran untuk semua role (admin & pegawai).
     * Filter: status, method, type. Cari: nomor referensi / kode booking / nama tamu.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->input('per_page', 20), 100));

        // Otomatis sinkronkan pembayaran gateway yang masih pending dari provider
        if (Payment::where('method', \App\Enums\PaymentMethod::PaymentGateway->value)->where('status', \App\Enums\PaymentStatus::Pending->value)->exists()) {
            try {
                app(\App\Services\PaymentGateway\PaymentGatewayService::class)->syncPendingPayments();
            } catch (\Throwable) {}
        }

        $payments = Payment::query()
            ->with([
                'booking:id,booking_code,customer_id',
                'booking.customer:id,name',
                'createdBy:id,name',
                'verifiedBy:id,name',
            ])
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->input('method'), fn ($query, $method) => $query->where('method', $method))
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('search'), function ($query) use ($request) {
                $term = '%' . trim((string) $request->input('search')) . '%';

                $query->where(function ($sub) use ($term) {
                    $sub->where('reference_number', 'like', $term)
                        ->orWhereHas('booking', function ($booking) use ($term) {
                            $booking->where('booking_code', 'like', $term)
                                ->orWhereHas('customer', function ($customer) use ($term) {
                                    $customer->where('name', 'like', $term);
                                });
                        });
                });
            })
            ->latest('created_at')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($payments);
    }

    /**
     * Catat pembayaran manual untuk suatu booking.
     */
    public function store(StorePaymentRequest $request, Booking $booking)
    {
        try {
            $payment = $this->paymentService->recordManualPayment(
                $booking,
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'message' => 'Pembayaran berhasil dicatat.',
                'data'    => $payment,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal mencatat pembayaran.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Detail satu pembayaran.
     */
    public function show(Payment $payment)
    {
        $payment->load(['booking', 'invoice', 'createdBy', 'verifiedBy', 'shift']);
        return response()->json(['data' => $payment]);
    }

    /**
     * Upload bukti pembayaran (untuk transfer).
     */
    public function uploadProof(Request $request, Payment $payment)
    {
        $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $updated = $this->paymentService->uploadProof($payment, $request->file('proof'));
        $autoVerified = $updated->status->value === PaymentStatus::Verified->value;

        return response()->json([
            'message' => $autoVerified
                ? 'Bukti tersimpan; pembayaran terverifikasi otomatis (referensi unik + nominal sesuai).'
                : 'Bukti pembayaran berhasil diunggah.',
            'auto_verified' => $autoVerified,
            'data'          => $updated,
        ]);
    }

    /**
     * Reversal (post-audit) — khusus admin, wajib alasan.
     * Pengganti kontrol "approve di depan": sistem berjalan otomatis, admin menangani pengecualian.
     */
    public function reverse(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Alasan pembatalan wajib diisi.',
            'reason.max'      => 'Alasan pembatalan maksimal 255 karakter.',
        ]);

        try {
            $updated = $this->paymentService->reverse($payment, $request->user()->id, $validated['reason']);

            return response()->json([
                'message' => 'Pembayaran dibatalkan dan status tagihan disesuaikan.',
                'data'    => $updated,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal membatalkan pembayaran.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verifikasi pembayaran — admin only (sudah dibatasi di route).
     */
    public function verify(Request $request, Payment $payment)
    {
        try {
            $updated = $this->paymentService->verify($payment, $request->user()->id);

            return response()->json([
                'message' => 'Pembayaran berhasil diverifikasi.',
                'data'    => $updated,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal verifikasi pembayaran.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Tolak / batalkan pembayaran pending — admin only.
     */
    public function reject(Request $request, Payment $payment)
    {
        $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $updated = $this->paymentService->reject($payment, $request->user()->id, $request->input('reason'));

            return response()->json([
                'message' => 'Pembayaran berhasil ditolak.',
                'data'    => $updated,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal menolak pembayaran.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }
}
