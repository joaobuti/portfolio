<?php

declare(strict_types=1);

return [
    'handle' => env('INFINITEPAY_HANDLE', ''),
    'api_key' => env('INFINITEPAY_API_KEY', ''),
    'webhook_url' => env('INFINITEPAY_WEBHOOK_URL', ''),
    'redirect_url' => env('INFINITEPAY_REDIRECT_URL'),
    'checkout_ttl_seconds' => 604800,
];
