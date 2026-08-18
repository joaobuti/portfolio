<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // Human-readable stable identifier. This is the public route key —
            // /api/v1/plans/pro reads better than a UUID and is safe to expose
            // because a plan is not customer data.
            $table->string('code')->unique();

            $table->string('name');
            $table->text('description')->nullable();

            // Money is always (integer minor units, currency) — never a float
            // and never a bare number. See App\Casts\MoneyCast.
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);

            $table->string('interval');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('features')->default('[]');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
