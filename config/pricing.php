<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Pricing service
    |--------------------------------------------------------------------------
    |
    | The Node/TypeScript service that owns regional tax and coupon rules. It is
    | called synchronously in the path of a plan change, which is the whole
    | reason every value below exists: a synchronous dependency without a
    | timeout and a breaker is an outage waiting for the other service to have
    | a bad day.
    |
    */

    'base_uri' => env('PRICING_SERVICE_URL', 'http://pricing:3000'),

    /*
    | Seconds. Deliberately short — this sits between a customer pressing a
    | button and the response. The service's own request timeout is 5s, so a
    | client timeout below that means we give up before it does and the
    | fallback is ours to control.
    */
    'timeout' => (float) env('PRICING_SERVICE_TIMEOUT', 2.0),
    'connect_timeout' => (float) env('PRICING_SERVICE_CONNECT_TIMEOUT', 1.0),

    /*
    | One retry, and only for connection-level failures. Retrying a synchronous
    | call in a user's request doubles the worst case they wait for; anything
    | more belongs to the breaker, not the retry loop.
    */
    'retries' => (int) env('PRICING_SERVICE_RETRIES', 1),
    'retry_delay_ms' => (int) env('PRICING_SERVICE_RETRY_DELAY_MS', 100),

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After `failure_threshold` consecutive failures the circuit opens and every
    | call short-circuits to the degraded fallback for `open_seconds`, without
    | touching the network. This is what stops a slow dependency from turning
    | every plan change into a 2-second wait.
    |
    | State lives in the cache (Redis) so all web and worker containers share
    | one view of the dependency's health. See ADR-0005.
    |
    */

    'breaker' => [
        'failure_threshold' => (int) env('PRICING_BREAKER_THRESHOLD', 5),
        'open_seconds' => (int) env('PRICING_BREAKER_OPEN_SECONDS', 30),
        'cache_key' => 'pricing:breaker',
    ],

    /*
    |--------------------------------------------------------------------------
    | Degraded fallback
    |--------------------------------------------------------------------------
    |
    | What we do when the service cannot be reached. `untaxed` returns the
    | amount with no tax and no discount, flagged as degraded so the caller can
    | decide whether that is acceptable. It is the right answer for a preview
    | and the wrong one for a charge — which is why the flag exists rather than
    | a silent zero. See ADR-0005 for why this is not configurable per call.
    |
    */

    'fallback' => env('PRICING_FALLBACK', 'untaxed'),

    /*
    | Turning this off makes every quote degraded without a network call. Useful
    | in the test suite and for local work with no pricing container running.
    */
    'enabled' => (bool) env('PRICING_SERVICE_ENABLED', true),

    /*
    | Used when a customer has no region recorded. The billing core has no
    | address field yet — when it grows one, this becomes the fallback rather
    | than the only source.
    */
    'default_region' => env('PRICING_DEFAULT_REGION', 'BR'),

];
