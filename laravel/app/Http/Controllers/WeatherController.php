<?php

namespace App\Http\Controllers;

use App\Services\X402Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherController extends Controller
{
    private const PRICE = '10000'; // $0.01 in USDC atomic units

    public function __invoke(Request $request, X402Payments $x402): JsonResponse
    {
        $requirements = $x402->requirements(self::PRICE);
        $description  = 'Weather data';

        // 1. No payment attached: tell the client what we accept.
        if (!$request->hasHeader('PAYMENT-SIGNATURE')) {
            return $x402->paymentRequired($request, $requirements, $description);
        }

        $payload = $x402->decodePayload($request);
        if ($payload === null) {
            return $x402->paymentRequired($request, $requirements, $description, 'Invalid PAYMENT-SIGNATURE header');
        }

        // 2. Verify (no funds move yet).
        $verify = $x402->verify($payload, $requirements);
        if (empty($verify['isValid'])) {
            return $x402->paymentRequired($request, $requirements, $description, $verify['invalidReason'] ?? 'Payment verification failed');
        }

        // 3. Do the work. This is your actual product.
        $data = ['city' => 'Sydney', 'temp_c' => 24, 'conditions' => 'Sunny'];

        // 4. Settle. Don't hand over the content if payment didn't go through.
        $settle = $x402->settle($payload, $requirements);
        if (empty($settle['success'])) {
            return $x402->paymentRequired($request, $requirements, $description, $settle['errorReason'] ?? 'Settlement failed');
        }

        return response()->json($data)->header('PAYMENT-RESPONSE', base64_encode(json_encode($settle)));
    }
}
