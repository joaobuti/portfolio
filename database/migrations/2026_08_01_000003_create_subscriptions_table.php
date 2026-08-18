<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            // Set when a downgrade is queued for the end of the current period.
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans')->nullOnDelete();

            $table->string('status')->default(SubscriptionStatus::Trialing->value);

            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');

            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->timestamps();

            // The scheduler's query: "what is due today and still renewable?"
            $table->index(['status', 'current_period_end']);
            $table->index(['customer_id', 'status']);
        });

        // The business rule "a customer may hold only one subscription that
        // grants access" is enforced here, not only in the service layer.
        //
        // A service-level check is a read followed by a write, and two
        // concurrent requests can both pass the read. A partial unique index
        // cannot be raced: the database rejects the second insert. The service
        // check stays as well, because it produces a good error message — this
        // is the backstop that makes it correct.
        $accessGranting = array_map(
            static fn (SubscriptionStatus $status): string => "'{$status->value}'",
            array_filter(
                SubscriptionStatus::cases(),
                static fn (SubscriptionStatus $status): bool => $status->grantsAccess(),
            ),
        );

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX subscriptions_one_access_granting_per_customer
             ON subscriptions (customer_id) WHERE status IN (%s)',
            implode(', ', $accessGranting),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
