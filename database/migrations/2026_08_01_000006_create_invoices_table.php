<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer owes for one billing event.
 *
 * This is where the pricing service's answer finally lands. Until now the tax
 * and discount it computed were shown in a preview and thrown away, because
 * there was nothing to attach them to — see ADR-0005. The four amount columns
 * below are that attachment point, and they are stored rather than recomputed
 * for the reason every ledger is: the rate that applied on the day is a fact,
 * and next year's rate must not silently rewrite last year's invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Nullable so a one-off invoice is representable without inventing
            // a subscription for it. Restricted rather than cascading: an
            // invoice is a financial record and must outlive the subscription
            // that caused it.
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('status')->index();

            // Integer minor units, never a float. ADR-0002.
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->string('currency', 3);

            // True when the pricing service was unreachable and the amounts
            // carry no tax. Such an invoice must never be charged — see
            // BillingService. Persisted rather than inferred because "was this
            // taxed?" cannot be answered later from a zero tax column: a
            // zero-rated region also produces zero.
            $table->boolean('pricing_degraded')->default(false);

            // The service period this invoice covers. Nullable for a one-off.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();

            // How many times we have called the gateway for this invoice.
            $table->unsignedSmallInteger('attempt_count')->default(0);

            // When the dunning sweep should try again. Null means never —
            // either the invoice is settled, or it was written off.
            //
            // Computed at failure time rather than derived by the sweep: the
            // code that just failed knows the reason and the attempt count,
            // and that is the only place the retry policy should live. A sweep
            // that re-derived it would be a second implementation of the same
            // rule, and the two would eventually disagree.
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamps();

            // The dunning sweep's whole query, covered by one index.
            $table->index(['status', 'next_attempt_at']);

            // One invoice per subscription per period. The guard against a
            // retried renewal job billing the same month twice — the most
            // expensive bug this module can have, and one that must not depend
            // on the queue behaving.
            $table->unique(['subscription_id', 'period_start'], 'invoices_subscription_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
