<?php

namespace App\Http\Controllers;

use App\Services\PaymentGateway\PaymentGatewayService;
use Illuminate\Http\Request;

/**
 * WebhookController — endpoint publik, tanpa CSRF, tanpa session.
 * Harus terdaftar di luar group middleware 'web' dan 'auth'.
 */
class WebhookController extends Controller
{
    public function __construct(protected PaymentGatewayService $gatewayService) {}

    /**
     * POST /webhooks/payment-gateway
     * Menerima callback dari payment gateway, memverifikasi signature,
     * dan memproses perubahan status pembayaran.
     */
    public function handlePaymentGateway(Request $request)
    {
        $rawPayload = $request->getContent();
        $headers    = $request->headers->all();

        // Flatten header arrays (Laravel wraps them in arrays)
        $flatHeaders = array_map(fn($v) => is_array($v) ? $v[0] : $v, $headers);

        $result = $this->gatewayService->handleWebhook($rawPayload, $flatHeaders);

        return match ($result['status']) {
            'rejected'        => response()->json($result, 401),
            'amount_mismatch' => response()->json($result, 422),
            'error'           => response()->json($result, 500),
            default           => response()->json($result, 200),
        };
    }
}
