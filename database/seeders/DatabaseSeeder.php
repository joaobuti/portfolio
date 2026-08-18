<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        Customer::factory()->create([
            'name' => 'Cliente Demo',
            'email' => 'demo@example.test',
        ]);
    }
}
