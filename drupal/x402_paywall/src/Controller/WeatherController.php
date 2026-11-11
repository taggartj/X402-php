<?php

declare(strict_types=1);

namespace Drupal\x402_paywall\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\x402_paywall\X402Facilitator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Example paid endpoint: GET /api/weather.
 */
class WeatherController extends ControllerBase {

  /**
   * $0.01 in USDC atomic units.
   */
  protected const PRICE = '10000';

  public function __construct(protected X402Facilitator $x402) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('x402_paywall.facilitator'));
  }

  public function build(Request $request): JsonResponse {
    $requirements = $this->x402->requirements(self::PRICE);
    $description = 'Weather data';

    // 1. No payment attached: tell the client what we accept.
    if (!$request->headers->has('PAYMENT-SIGNATURE')) {
      return $this->x402->paymentRequired($request, $requirements, $description);
    }

    $payload = $this->x402->decodePayload($request);
    if ($payload === NULL) {
      return $this->x402->paymentRequired($request, $requirements, $description, 'Invalid PAYMENT-SIGNATURE header');
    }

    // 2. Verify (no funds move yet).
    $verify = $this->x402->verify($payload, $requirements);
    if (empty($verify['isValid'])) {
      return $this->x402->paymentRequired($request, $requirements, $description, $verify['invalidReason'] ?? 'Payment verification failed');
    }

    // 3. Do the work. This is your actual product.
    $data = ['city' => 'Sydney', 'temp_c' => 24, 'conditions' => 'Sunny'];

    // 4. Settle. Don't hand over the content if payment didn't go through.
    $settle = $this->x402->settle($payload, $requirements);
    if (empty($settle['success'])) {
      return $this->x402->paymentRequired($request, $requirements, $description, $settle['errorReason'] ?? 'Settlement failed');
    }

    $response = new JsonResponse($data);
    $response->headers->set('PAYMENT-RESPONSE', base64_encode(json_encode($settle)));
    return $response;
  }

}
