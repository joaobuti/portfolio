<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Risk worker transport
    |--------------------------------------------------------------------------
    |
    | Cross-service messaging is a Redis **list**, not a pub/sub channel. This
    | was decided in ADR-0003 before the worker existed: pub/sub has no
    | delivery guarantee, so a subscriber that is restarting when a message is
    | published never sees it. A list is durable and lets a consumer use the
    | reliable-queue pattern (BLMOVE onto a processing list), which is what
    | makes "the worker crashed mid-message" survivable.
    |
    | The names are configuration rather than constants because the Python
    | service has to agree with them, and two repositories agreeing on a value
    | is easier when it comes from the environment in both.
    |
    */

    'enabled' => (bool) env('RISK_WORKER_ENABLED', true),

    'streams' => [
        // Laravel publishes here; the worker consumes.
        'events' => env('RISK_EVENTS_KEY', 'risk:events'),

        // The worker publishes here; Laravel consumes.
        'scores' => env('RISK_SCORES_KEY', 'risk:scores'),
    ],

    /*
    | How many scores one drain of the inbound list handles before returning.
    | Bounded so a scheduled run cannot turn into an unbounded loop if the
    | worker is producing faster than we consume.
    */
    'scores_per_run' => (int) env('RISK_SCORES_PER_RUN', 500),

];
