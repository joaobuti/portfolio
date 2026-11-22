<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Enums\CheckoutStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/** Current-state gauges, not accounting totals or cumulative counters. */
final readonly class OperationalMetrics
{
    private const string CACHE_KEY = 'observability:billing-metrics:v1';

    public function __construct(private Clock $clock) {}

    public function render(): string
    {
        return Cache::remember(self::CACHE_KEY, 15, fn (): string => $this->collect());
    }

    private function collect(): string
    {
        $now = $this->clock->now();
        $invoices = $this->statusCounts('invoices', array_column(InvoiceStatus::cases(), 'value'));
        $subscriptions = $this->statusCounts('subscriptions', array_column(SubscriptionStatus::cases(), 'value'));
        $notifications = $this->statusCounts('checkout_notifications', ['pending', 'confirmed', 'reconciliation']);
        // Match the effective expiry shown by InvoiceResource, without writing
        // rows or loading invoices/checkouts during a monitoring scrape.
        $checkouts = DB::table('payment_checkouts')
            ->selectRaw('CASE WHEN status = ? AND expires_at <= ? THEN ? ELSE status END AS metric_status', [
                CheckoutStatus::AwaitingPayment->value, $now, CheckoutStatus::Expired->value,
            ])
            ->selectRaw('COUNT(*) AS aggregate')->groupBy('metric_status')->pluck('aggregate', 'metric_status');
        $checkoutCounts = [];
        foreach (CheckoutStatus::cases() as $state) {
            $checkoutCounts[$state->value] = $this->aggregateCount($checkouts->get($state->value, 0));
        }
        $pending = DB::table('checkout_notifications')->where('status', 'pending');
        $oldest = (clone $pending)->min('created_at');
        $oldestAge = is_string($oldest) ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp()) : 0;

        return $this->gauge('snapshot_timestamp_seconds', 'Unix time when this cached snapshot was collected.', ['' => $now->getTimestamp()])
            .$this->gauge('invoices', 'Persisted invoices by current status.', $invoices)
            .$this->gauge('subscriptions', 'Persisted subscriptions by current status.', $subscriptions)
            .$this->gauge('checkouts', 'Checkouts by effective status, including local expiry.', $checkoutCounts)
            .$this->gauge('checkout_notifications', 'Stored payment notifications by verification status.', $notifications)
            .$this->gauge('degraded_open_invoices', 'Open invoices blocked from collection by degraded pricing.', [
                '' => DB::table('invoices')->where('status', InvoiceStatus::Open->value)->where('pricing_degraded', true)->count(),
            ])
            .$this->gauge('collection_due_invoices', 'Open non-degraded invoices with a due collection timestamp.', [
                '' => Invoice::query()->dueForCollection($now)->count(),
            ])
            .$this->gauge('checkout_verifications_due', 'Pending notifications whose next verification is due.', [
                '' => $pending->where('next_verification_at', '<=', $now)->count(),
            ])
            .$this->gauge('oldest_pending_checkout_notification_age_seconds', 'Age of the oldest pending notification; zero when none exist.', ['' => $oldestAge]);
    }

    /**
     * @param  list<string>  $states
     * @return array<string, int>
     */
    private function statusCounts(string $table, array $states): array
    {
        $counts = DB::table($table)->select('status')->selectRaw('COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $values = [];
        foreach ($states as $state) {
            $values[$state] = $this->aggregateCount($counts->get($state, 0));
        }

        return $values;
    }

    private function aggregateCount(mixed $value): int
    {
        if ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Invalid aggregate count.');
    }

    /** @param array<string, int> $values Empty key indicates an unlabelled gauge. */
    private function gauge(string $suffix, string $help, array $values): string
    {
        $name = 'portfolio_billing_'.$suffix;
        $text = '# HELP '.$name.' '.$help."\n".'# TYPE '.$name." gauge\n";
        foreach ($values as $status => $value) {
            // Both the label name and every value come from the fixed states
            // above; user-controlled values can never expand label cardinality.
            $text .= $name.($status === '' ? '' : '{status="'.$status.'"}').' '.$value."\n";
        }

        return $text;
    }
}
