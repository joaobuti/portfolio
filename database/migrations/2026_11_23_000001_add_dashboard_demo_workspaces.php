<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('scenario');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        foreach (['customers', 'users'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('demo_workspace_id')->nullable()->constrained()->restrictOnDelete();
                $table->index('demo_workspace_id');
            });
        }
        Schema::table('users', fn (Blueprint $table) => $table->rememberToken());
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('demo_payment_status')->nullable();
        });
    }

    public function down(): void
    {
        if (Illuminate\Support\Facades\DB::table('demo_workspaces')->exists()
            || Illuminate\Support\Facades\DB::table('customers')->whereNotNull('demo_workspace_id')->exists()
            || Illuminate\Support\Facades\DB::table('users')->whereNotNull('demo_workspace_id')->exists()) {
            throw new LogicException('Prune synthetic demo generations before reverting their isolation schema.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropRememberToken());
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('demo_payment_status'));
        foreach (['users', 'customers'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('demo_workspace_id'));
        }
        Schema::dropIfExists('demo_workspaces');
    }
};
