<?php

declare(strict_types=1);

namespace Drupal\x402_paywall;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Talks to an x402 (v2) facilitator and builds 402 responses.
 *
 * Check field names against the x402 specs/ folder and your facilitator's docs.
 */
class X402Facilitator {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  /**
   * The single payment option accepted, priced per call.
   *
   * @param string $amount
   *   Atomic units. USDC has 6 decimals, so '10000' is $0.01.
   */
  public function requirements(string $amount): array {
    $config = $this->configFactory->get('x402_paywall.settings');
    return [
      'scheme' => 'exact',
      'network' => $config->get('network'),
      'amount' => $amount,
      'asset' => $config->get('asset'),
      'payTo' => $config->get('pay_to'),
      'maxTimeoutSeconds' => 60,
      'extra' => [
        'name' => $config->get('asset_name'),
        'version' => $config->get('asset_version'),
      ],
    ];
  }

  /**
   * Builds a 402 response with the PAYMENT-REQUIRED header.
   */
  public function paymentRequired(Request $request, array $requirements, string $description, string $error = ''): JsonResponse {
    $body = [
      'x402Version' => 2,
      'error' => $error ?: 'Payment required',
      'resource' => [
        'url' => $request->getUri(),
        'description' => $description,
        'mimeType' => 'application/json',
      ],
      'accepts' => [$requirements],
    ];
    $response = new JsonResponse($body, 402);
    $response->headers->set('PAYMENT-REQUIRED', base64_encode(json_encode($body, JSON_UNESCAPED_SLASHES)));
    return $response;
  }

  /**
   * Decodes the base64 JSON in PAYMENT-SIGNATURE, or NULL if missing/invalid.
   */
  public function decodePayload(Request $request): ?array {
    $header = $request->headers->get('PAYMENT-SIGNATURE');
    if (!$header) {
      return NULL;
    }
    $json = base64_decode($header, TRUE);
    $data = $json === FALSE ? NULL : json_decode($json, TRUE);
    return is_array($data) ? $data : NULL;
  }

  /**
   * POST /verify: checks the signature, moves no funds.
   */
  public function verify(array $payload, array $requirements): array {
    return $this->post('/verify', $payload, $requirements, 'isValid', 'invalidReason');
  }

  /**
   * POST /settle: submits the payment on-chain via the facilitator.
   */
  public function settle(array $payload, array $requirements): array {
    return $this->post('/settle', $payload, $requirements, 'success', 'errorReason');
  }

  protected function post(string $path, array $payload, array $requirements, string $okKey, string $reasonKey): array {
    $config = $this->configFactory->get('x402_paywall.settings');
    try {
      $response = $this->httpClient->request('POST', rtrim((string) $config->get('facilitator_url'), '/') . $path, [
        'json' => [
          'x402Version' => 2,
          'paymentPayload' => $payload,
          'paymentRequirements' => $requirements,
        ],
        'timeout' => (int) $config->get('timeout'),
        // Facilitators may return 4xx with a useful JSON body.
        'http_errors' => FALSE,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      return is_array($data) ? $data : [$okKey => FALSE, $reasonKey => 'invalid_facilitator_response'];
    }
    catch (\Throwable $e) {
      $this->logger->error('x402 facilitator call to @path failed: @message', [
        '@path' => $path,
        '@message' => $e->getMessage(),
      ]);
      return [$okKey => FALSE, $reasonKey => 'facilitator_unreachable'];
    }
  }

}
