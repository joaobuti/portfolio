<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\Currency;
use App\Models\Plan;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue.
 *
 * Create missing demo plans without overwriting catalogue edits on restart.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Para quem está começando.',
                'amount' => Money::ofMajor('29.90', Currency::BRL),
                'interval' => BillingInterval::Monthly,
                'trial_days' => 14,
                'is_active' => true,
                'features' => ['projects' => 3, 'support' => 'email'],
                'sort_order' => 1,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Para times em crescimento.',
                'amount' => Money::ofMajor('99.90', Currency::BRL),
                'interval' => BillingInterval::Monthly,
                'trial_days' => 14,
                'is_active' => true,
                'features' => ['projects' => 25, 'support' => 'priority'],
                'sort_order' => 2,
            ],
            [
                'code' => 'pro-annual',
                'name' => 'Pro Anual',
                'description' => 'Pro com dois meses de desconto.',
                'amount' => Money::ofMajor('999.00', Currency::BRL),
                'interval' => BillingInterval::Yearly,
                'trial_days' => 0,
                'is_active' => true,
                'features' => ['projects' => 25, 'support' => 'priority'],
                'sort_order' => 3,
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Sem limites de projetos.',
                'amount' => Money::ofMajor('499.00', Currency::BRL),
                'interval' => BillingInterval::Monthly,
                'trial_days' => 0,
                'is_active' => true,
                'features' => ['projects' => null, 'support' => 'dedicated'],
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $attributes) {
            Plan::query()->firstOrCreate(['code' => $attributes['code']], $attributes);
        }
    }
}
