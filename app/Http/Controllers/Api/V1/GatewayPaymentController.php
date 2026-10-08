<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Http\Request;
use Exception;

class GatewayPaymentController extends Controller
{
    public function __construct(protected PaymentGatewayService $gatewayService) {}

    /**
     * Buat permintaan pembayaran via gateway (QRIS/VA/e-wallet).
     */
    public function store(Request $request, Booking $booking)
    {
        $request->validate([
            'channel' => ['required', 'in:qris,va,ewallet'],
        ]);

        try {
            $payment = $this->gatewayService->createGatewayPayment(
                $booking,
                $request->only('channel'),
                $request->user()->id
            );

            return response()->json([
                'message'        => 'Permintaan pembayaran berhasil dibuat.',
                'data'           => $payment,
                'payment_info'   => $payment->gateway_payload,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Gagal membuat permintaan pembayaran.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }
}
