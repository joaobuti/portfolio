<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RiskBand;
use App\Models\Customer;
use App\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use JsonException;

/**
 * Drains the risk worker's answers back into the billing core.
 *
 * The return leg of the only cross-language round trip in the system. It is a
 * command rather than a long-running process because the volume is bounded by
 * the number of failed payments — a few per minute at worst — and a scheduled
 * drain is one fewer daemon to supervise than a second queue worker.
 *
 * ## Everything here is defensive
 *
 * The producer is a different service in a different language on its own
 * deploy cycle. A message that does not parse, names a customer that no longer
 * exists, or carries a band this version has never heard of must be dropped
 * and logged — never allowed to abort the drain and strand every message
 * behind it.
 */
final class ConsumeRiskScoresCommand extends Command
{
    protected $signature = 'risk:consume-scores
                            {--limit= : Stop after this many messages}';

    protected $description = 'Apply risk scores published by the Python risk worker';

    public function handle(Clock $clock): int
    {
        if (config('risk.enabled') !== true) {
            $this->components->warn('Risk worker integration is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->limit();
        $key = $this->stream();

        $applied = 0;
        $skipped = 0;

        for ($i = 0; $i < $limit; $i++) {
            $raw = Redis::connection('messaging')->lpop($key);

            if (! is_string($raw)) {
                break;
            }

            $this->apply($raw, $clock) ? $applied++ : $skipped++;
        }

        $this->components->info("{$applied} score(s) applied, {$skipped} skipped.");

        Log::info('Risk score drain finished', [
            'applied' => $applied,
            'skipped' => $skipped,
        ]);

        return self::SUCCESS;
    }

    private function apply(string $raw, Clock $clock): bool
    {
        try {
            /** @var array<string, mixed> $message */
            $message = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            Log::warning('Discarded an unparseable risk score', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $data = $message['data'] ?? null;

        if (! is_array($data)) {
            Log::warning('Discarded a risk score with no data object');

            return false;
        }

        $band = RiskBand::tryFrom(is_string($data['band'] ?? null) ? $data['band'] : '');
        $score = $data['score'] ?? null;

        if ($band === null || ! is_int($score)) {
            // A band this version does not know is a producer running ahead of
            // us. Logged loudly: it means a deploy is needed, not that the
            // message was junk.
            Log::warning('Discarded a risk score this version cannot interpret', [
                'band' => $data['band'] ?? null,
                'score' => $score,
            ]);

            return false;
        }

        $customer = Customer::query()
            ->where('uuid', is_string($data['customer_uuid'] ?? null) ? $data['customer_uuid'] : '')
            ->first();

        if ($customer === null) {
            Log::info('Discarded a risk score for an unknown customer', [
                'customer_uuid' => $data['customer_uuid'] ?? null,
            ]);

            return false;
        }

        $customer->forceFill([
            'risk_score' => max(0, min(100, $score)),
            'risk_band' => $band,
            'risk_scored_at' => $clock->now(),
        ])->save();

        return true;
    }

    private function limit(): int
    {
        $option = $this->option('limit');

        // `is_numeric`, not `is_string`: the option arrives as a string from
        // the CLI and as an int when a test calls artisan() with an array, and
        // a check that only accepted one of those silently ignored the flag in
        // the other.
        if (is_numeric($option)) {
            return max(1, (int) $option);
        }

        $configured = config('risk.scores_per_run');

        return is_int($configured) ? max(1, $configured) : 500;
    }

    private function stream(): string
    {
        $key = config('risk.streams.scores');

        return is_string($key) ? $key : 'risk:scores';
    }
}
