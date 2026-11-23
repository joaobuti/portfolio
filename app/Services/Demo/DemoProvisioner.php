<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DemoWorkspace;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\BillingCalendar;
use App\Support\Clock;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DemoProvisioner
{
    public function __construct(private DemoConfiguration $configuration, private Clock $clock, private BillingCalendar $calendar) {}

    /** @return array{workspace: DemoWorkspace, user: User} */
    public function create(string $scenario, string $profile, ?int $previous): array
    {
        $this->configuration->ensureEnabled(true);

        /** @var array{workspace: DemoWorkspace, user: User} $identity */
        $identity = Cache::lock('demo:provision', 30)->block(10, fn (): array => DB::transaction(function () use ($scenario, $profile, $previous): array {
            abort_if(DemoWorkspace::query()->count() >= config()->integer('demo.max_workspaces'), 429, 'Demo capacity reached.');
            $workspace = DemoWorkspace::query()->create(['scenario' => $scenario,
                'expires_at' => $this->clock->now()->addMinutes(config()->integer('demo.ttl_minutes'))]);
            $basic = $this->plan('demo-essential', 'Essencial', 10000);
            $business = $this->plan('demo-business', 'Business', 20000);
            $ana = $this->fixture($workspace, 'Ana Martins', $basic, $scenario === 'pending');
            $this->fixture($workspace, 'Bruno Lima', $basic, true);
            $this->fixture($workspace, 'Carla Souza', $business, false);
            $this->account($workspace, UserRole::Customer, $ana);
            $this->account($workspace, UserRole::Staff, null);
            if ($previous !== null) {
                DemoWorkspace::query()->whereKey($previous)->update(['expires_at' => $this->clock->now()]);
            }

            return ['workspace' => $workspace, 'user' => $this->identity($workspace->id, $profile)];
        }));

        return $identity;
    }

    public function workspace(int $id): DemoWorkspace
    {
        $this->configuration->ensureEnabled();

        return DemoWorkspace::query()->whereKey($id)->where('expires_at', '>', $this->clock->now())->firstOrFail();
    }

    public function identity(int $id, string $profile): User
    {
        return User::query()->where('demo_workspace_id', $id)->where('role', $profile)->firstOrFail();
    }

    private function plan(string $code, string $name, int $amount): Plan
    {
        return Plan::query()->firstOrCreate(['code' => $code], ['name' => $name,
            'description' => 'Synthetic demo plan', 'amount' => Money::ofMinor($amount, Currency::BRL),
            'interval' => BillingInterval::Monthly, 'trial_days' => 0, 'is_active' => true,
            'features' => ['simulation' => true], 'sort_order' => $amount]);
    }

    private function fixture(DemoWorkspace $workspace, string $name, Plan $plan, bool $pending): Customer
    {
        $customer = new Customer;
        $customer->forceFill(['name' => $name, 'email' => Str::slug($name).'.'.$workspace->uuid.'@example.test',
            'demo_workspace_id' => $workspace->id])->save();
        $now = $this->clock->now();
        $local = $now->setTimezone('America/Sao_Paulo');
        $start = $local->startOfMonth()->day(10);
        if ($start > $local) {
            $start = $start->subMonthNoOverflow();
        }
        $period = $this->calendar->firstPeriod($now, BillingInterval::Monthly, $plan->amount);
        $subscription = Subscription::query()->create(['customer_id' => $customer->id, 'plan_id' => $plan->id,
            'status' => $pending ? SubscriptionStatus::PendingPayment : SubscriptionStatus::Active,
            'current_period_start' => $pending ? $now : $start->setTimezone('UTC'),
            'current_period_end' => $period->end, 'cancel_at_period_end' => false]);
        Invoice::query()->create(['customer_id' => $customer->id, 'subscription_id' => $subscription->id,
            'kind' => $pending ? InvoiceKind::Initial : InvoiceKind::Renewal,
            'status' => $pending ? InvoiceStatus::Open : InvoiceStatus::Paid,
            'subtotal' => $amount = $pending ? $period->amount : $plan->amount,
            'discount' => Money::zero(Currency::BRL), 'tax' => Money::zero(Currency::BRL), 'total' => $amount,
            'pricing_degraded' => false, 'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end, 'due_at' => $now,
            'paid_at' => $pending ? null : $now, 'next_attempt_at' => $pending ? $now : null,
            'attempt_count' => 0]);

        return $customer;
    }

    private function account(DemoWorkspace $workspace, UserRole $role, ?Customer $customer): void
    {
        $user = new User;
        $user->forceFill(['name' => $role === UserRole::Customer ? 'Ana Martins' : 'Demo operator',
            'email' => $role->value.'.'.$workspace->uuid.'@example.test', 'password' => Str::random(64),
            'role' => $role, 'customer_id' => $customer?->id, 'demo_workspace_id' => $workspace->id])->save();
    }
}
