<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | `fake` takes no money and needs no account, so the stack runs for anyone
    | who has just cloned the repository. It is deterministic — the amount
    | decides the outcome — so the unhappy paths are reachable on demand rather
    | than by luck. See App\Services\Billing\FakePaymentGateway.
    |
    */

    'gateway' => env('BILLING_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Dunning
    |--------------------------------------------------------------------------
    |
    | How hard we chase a failed payment before writing it off.
    |
    | The schedule is in days between attempts, and its length is the attempt
    | limit: four entries means four retries after the first failure. Spread
    | out rather than tight, because the common cause is a card that will be
    | topped up on payday, not a transient fault.
    |
    | A failure the reason enum marks as not worth retrying — an expired card —
    | skips the schedule entirely. Retrying it eight times is eight guaranteed
    | failures, eight gateway fees, and a customer nobody told to update
    | their card.
    |
    */

    'dunning' => [
        'retry_days' => [1, 3, 5, 7],

        /*
        | Days after issue that an invoice is due. Zero means immediately,
        | which is what a subscription renewal wants: the service period has
        | already started.
        */
        'due_in_days' => (int) env('BILLING_DUE_IN_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Charges go to the same queue as renewals so a single worker pool handles
    | the whole billing cycle in order, rather than racing itself across two.
    |
    */

    'queue' => env('BILLING_QUEUE', 'billing'),

];
