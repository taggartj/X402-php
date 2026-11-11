<?php

// Copy to config/x402.php and set values in .env.
return [
    // Testnet facilitator. Use a production facilitator for real money.
    'facilitator_url' => env('X402_FACILITATOR_URL', 'https://x402.org/facilitator'),
    'pay_to'          => env('X402_PAY_TO'),
    'network'         => env('X402_NETWORK', 'eip155:84532'), // Base Sepolia (CAIP-2)
    'asset'           => env('X402_ASSET', '0x036CbD53842c5426634e7929541eC2318f3dCF7e'), // USDC, Base Sepolia
    'asset_name'      => env('X402_ASSET_NAME', 'USDC'),
    'asset_version'   => env('X402_ASSET_VERSION', '2'),
    'timeout'         => 30,
];
