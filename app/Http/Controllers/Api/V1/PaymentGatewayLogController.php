<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayLog;
use Illuminate\Http\Request;

class PaymentGatewayLogController extends Controller
{
    /**
     * GET /api/v1/payment-gateway-logs — admin only
     */
    public function index(Request $request)
    {
        $query = PaymentGatewayLog::latest('received_at');

        if ($request->has('provider')) {
            $query->where('provider', $request->provider);
        }

        if ($request->has('processed')) {
            $query->where('processed', $request->boolean('processed'));
        }

        if ($request->has('signature_valid')) {
            $query->where('signature_valid', $request->boolean('signature_valid'));
        }

        return response()->json(['data' => $query->paginate(20)]);
    }
}
