<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Jobs\Billing\CollectInvoice;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('keeps the real queue payload valid until an hour after collection becomes available', function (string $kind): void {
    freezeClockAt('2026-07-01 00:05:00');
    $due = CarbonImmutable::parse('2026-07-04 00:05:00');
    $delay = match ($kind) {
        'date' => $due,
        'seconds' => 3 * 24 * 60 * 60,
        'interval' => new DateInterval('P3D'),
        'none' => null,
        'past' => CarbonImmutable::parse('2026-06-30 00:05:00'),
    };
    $availableAt = in_array($kind, ['none', 'past'], true)
        ? CarbonImmutable::parse('2026-07-01 00:05:00')
        : $due;

    $invoice = Invoice::factory()->create(['due_at' => $availableAt]);
    $job = (new CollectInvoice($invoice->uuid))->delay($delay);
    $queue = Queue::connection('database');
    $queue->later($delay ?? 0, $job, '', 'billing');

    $payload = json_decode(DB::table('jobs')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['retryUntil'])->toBe($availableAt->addHour()->getTimestamp());

    freezeClockAt($availableAt->toDateTimeString());
    $queuedJob = $queue->pop('billing');
    expect($queuedJob)->not->toBeNull();

    app('queue.worker')->process('database', $queuedJob, new WorkerOptions);

    expect($invoice->fresh()?->status)->toBe(InvoiceStatus::Paid)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with(['date', 'seconds', 'interval', 'none', 'past']);
