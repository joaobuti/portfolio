<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per call to the payment gateway.
 *
 * Append-only by convention, like `subscription_changes`. Billing disputes and
 * "why was I charged twice?" are both answered from this table, so a row is
 * never updated and never deleted — a second attempt is a second row.
 *
 * The raw gateway response is kept alongside the mapped reason because support
 * eventually needs the vendor's own words, and because a mapping bug is only
 * findable if the input survived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->boolean('succeeded');

            // Null on success. One of App\Enums\PaymentFailureReason otherwise.
            $table->string('failure_reason')->nullable()->index();

            // The gateway's own code and message, unmapped. For humans.
            $table->string('gateway_code')->nullable();
            $table->text('gateway_message')->nullable();

            // The gateway's id for this charge. Nullable because a network
            // failure produces no reference at all — which is precisely the
            // case where reconciliation is hardest.
            $table->string('gateway_reference')->nullable()->index();

            $table->bigInteger('amount_cents');
            $table->string('currency', 3);

            $table->timestamp('attempted_at');

            // created_at only; a row that is never updated has no use for
            // updated_at, and carrying one implies an edit that never happens.
            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_attempts');
    }
};
