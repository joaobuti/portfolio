<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX subscriptions_one_access_granting_per_customer');
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_access_granting_per_customer ON subscriptions (customer_id) WHERE status IN ('pending_payment', 'trialing', 'active', 'past_due')");
        Schema::table('subscription_changes', function (Blueprint $table): void {
            $table->string('payment_status')->nullable();
            $table->foreignId('previous_pending_plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->timestamp('reverted_at')->nullable();
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('kind')->default('renewal');
            $table->foreignId('subscription_change_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('payment_deadline_at')->nullable();
            $table->dropUnique('invoices_subscription_period_unique');
        });
        DB::statement("CREATE UNIQUE INDEX invoices_subscription_period_unique ON invoices (subscription_id, period_start) WHERE kind <> 'adjustment'");
        Schema::create('plan_change_quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('tax_cents');
            $table->bigInteger('total_cents');
            $table->bigInteger('credit_cents');
            $table->bigInteger('charge_cents');
            $table->unsignedInteger('days_total');
            $table->unsignedInteger('days_remaining');
            $table->string('currency', 3);
            $table->string('region', 64);
            $table->string('coupon_code', 64)->nullable();
            $table->boolean('degraded');
            $table->boolean('is_upgrade');
            $table->boolean('apply_immediately');
            $table->foreignId('applied_change_id')->nullable()->unique()->constrained('subscription_changes')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('invoices')->where('kind', 'adjustment')->exists()
            || DB::table('subscriptions')->where('status', 'pending_payment')->exists()) {
            throw new LogicException('Adjustment financial records must be reconciled before reverting this migration.');
        }
        DB::statement('DROP INDEX subscriptions_one_access_granting_per_customer');
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_access_granting_per_customer ON subscriptions (customer_id) WHERE status IN ('trialing', 'active', 'past_due')");
        Schema::dropIfExists('plan_change_quotes');
        DB::statement('DROP INDEX invoices_subscription_period_unique');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subscription_change_id');
            $table->dropColumn(['kind', 'payment_deadline_at']);
            $table->unique(['subscription_id', 'period_start'], 'invoices_subscription_period_unique');
        });
        Schema::table('subscription_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('previous_pending_plan_id');
            $table->dropColumn(['payment_status', 'reverted_at']);
        });
    }
};
