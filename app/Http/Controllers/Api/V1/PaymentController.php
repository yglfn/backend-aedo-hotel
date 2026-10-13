<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Exception;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

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

        return response()->json([
            'message' => 'Bukti pembayaran berhasil diunggah.',
            'data'    => $updated,
        ]);
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
