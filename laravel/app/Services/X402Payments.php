<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Talks to an x402 (v2) facilitator and builds 402 responses.
 * Check field names against the x402 specs/ folder and your facilitator's docs.
 */
class X402Payments
{
    /** The single payment option this service accepts, priced per call. */
    public function requirements(string $amount): array
    {
        return [
            'scheme'            => 'exact',
            'network'           => config('x402.network'),
            'amount'            => $amount, // atomic units: USDC has 6 decimals, 10000 = $0.01
            'asset'             => config('x402.asset'),
            'payTo'             => config('x402.pay_to'),
            'maxTimeoutSeconds' => 60,
            'extra'             => [
                'name'    => config('x402.asset_name'),
                'version' => config('x402.asset_version'),
            ],
        ];
    }

    /** 402 response with the PAYMENT-REQUIRED header. */
    public function paymentRequired(Request $request, array $requirements, string $description, string $error = ''): JsonResponse
    {
        $body = [
            'x402Version' => 2,
            'error'       => $error ?: 'Payment required',
            'resource'    => [
                'url'         => $request->fullUrl(),
                'description' => $description,
                'mimeType'    => 'application/json',
            ],
            'accepts'     => [$requirements],
        ];

        return response()->json($body, 402)->header(
            'PAYMENT-REQUIRED',
            base64_encode(json_encode($body, JSON_UNESCAPED_SLASHES))
        );
    }

    /** Decode the base64 JSON in PAYMENT-SIGNATURE, or null if missing/invalid. */
    public function decodePayload(Request $request): ?array
    {
        $header = $request->header('PAYMENT-SIGNATURE');
        if (!$header) {
            return null;
        }
        $json = base64_decode($header, true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** POST /verify: checks the signature, moves no funds. */
    public function verify(array $payload, array $requirements): array
    {
        return $this->post('/verify', $payload, $requirements, 'isValid', 'invalidReason');
    }

    /** POST /settle: submits the payment on-chain via the facilitator. */
    public function settle(array $payload, array $requirements): array
    {
        return $this->post('/settle', $payload, $requirements, 'success', 'errorReason');
    }

    private function post(string $path, array $payload, array $requirements, string $okKey, string $reasonKey): array
    {
        try {
            $response = Http::timeout(config('x402.timeout'))
                ->acceptJson()
                ->asJson()
                ->post(rtrim(config('x402.facilitator_url'), '/') . $path, [
                    'x402Version'         => 2,
                    'paymentPayload'      => $payload,
                    'paymentRequirements' => $requirements,
                ]);

            return $response->json() ?? [$okKey => false, $reasonKey => 'invalid_facilitator_response'];
        } catch (Throwable $e) {
            report($e);

            return [$okKey => false, $reasonKey => 'facilitator_unreachable'];
        }
    }
}
