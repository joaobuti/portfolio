<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Python worker's answer lands.
 *
 * Columns on `customers` rather than a `risk_assessments` history table: the
 * question this module actually asks is "how risky is this customer right
 * now?", and one row per payment failure would be a table nobody queries
 * except to take its last row. The audit trail of *why* already exists in
 * `charge_attempts`.
 *
 * That is a decision to revisit if risk ever needs to be trended over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // 0-100, or null for a customer who has never failed a payment.
            // Nullable rather than defaulting to zero: "never assessed" and
            // "assessed as no risk" are different facts, and a default would
            // make them indistinguishable.
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('risk_band')->nullable();
            $table->timestamp('risk_scored_at')->nullable();

            // The support queue's query: riskiest customers first.
            $table->index(['risk_band', 'risk_score']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['risk_band', 'risk_score']);
            $table->dropColumn(['risk_score', 'risk_band', 'risk_scored_at']);
        });
    }
};
