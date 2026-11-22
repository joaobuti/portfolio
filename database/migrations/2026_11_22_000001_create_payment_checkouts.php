<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('order_reference')->unique();
            $table->string('merchant_handle');
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->text('webhook_url');
            $table->text('redirect_url')->nullable();
            $table->text('url')->nullable();
            $table->timestamp('expires_at');
            $table->string('transaction_nsu')->nullable()->unique();
            $table->bigInteger('paid_amount_cents')->nullable();
            $table->unsignedSmallInteger('installments')->nullable();
            $table->string('capture_method')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('checkout_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('payment_checkout_id')->constrained()->restrictOnDelete();
            $table->string('transaction_nsu', 128);
            $table->string('slug', 256);
            $table->string('fingerprint', 64)->unique();
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('verification_count')->default(0);
            $table->timestamp('next_verification_at')->nullable()->index();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_notifications');
        Schema::dropIfExists('payment_checkouts');
    }
};
