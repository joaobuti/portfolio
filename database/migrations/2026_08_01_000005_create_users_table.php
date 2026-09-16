<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs after `customers`, which it references.
 *
 * A user is not a customer. The customer is a billing entity — who is charged,
 * what they are subscribed to. The user is an account that authenticates. Most
 * of the time one belongs to the other, but a staff user belongs to none and a
 * customer with two people managing the account has two users. Collapsing them
 * into one table is the shortcut that becomes a migration eighteen months in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // Stored as the enum's string value. A smallint would save four
            // bytes and cost every future reader a lookup table.
            $table->string('role')->index();

            // Null for staff, who are not scoped to one customer. The
            // application enforces that a customer-role user always has one;
            // the database cannot express "required only for some roles"
            // without a check constraint that would need editing for every
            // role added later.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
