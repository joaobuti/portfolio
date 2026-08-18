<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->restrictOnDelete();

            $table->string('type');

            // Signed: positive is charged to the customer, negative is credited.
            $table->bigInteger('proration_amount_cents');
            $table->char('currency', 3);

            // The workings are stored alongside the result so a support agent
            // can explain the number without re-running the calculation.
            $table->unsignedSmallInteger('days_in_period');
            $table->unsignedSmallInteger('days_remaining');

            $table->timestampTz('effective_at');

            // Append-only: there is no updated_at because nothing updates it.
            $table->timestampTz('created_at')->nullable();

            $table->index(['subscription_id', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
