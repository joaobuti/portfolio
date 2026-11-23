<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() || config('demo.enabled') === true) {
            return;
        }
        $this->call(PlanSeeder::class);

        Customer::query()->firstOrCreate(
            ['email' => 'demo@example.test'],
            ['name' => 'Cliente Demo'],
        );
    }
}
