<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedInteger('pricing_recovery_attempts')->default(0);
            $table->timestamp('pricing_retry_at')->nullable();
            $table->timestamp('repriced_at')->nullable();
            $table->string('pricing_region', 64)->nullable();
            $table->string('coupon_code', 64)->nullable();
            $table->index(['status', 'pricing_degraded', 'pricing_retry_at'], 'invoices_pricing_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_pricing_recovery_index');
            $table->dropColumn(['pricing_recovery_attempts', 'pricing_retry_at', 'repriced_at', 'pricing_region', 'coupon_code']);
        });
    }
};
