<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Customer;
use App\Models\User;

final readonly class DemoConfiguration
{
    public function ensureEnabled(bool $provisioning = false): void
    {
        abort_unless(config('demo.enabled') === true, 404);
        abort_unless(app()->environment('demo') && config('billing.gateway') === 'fake'
            && config('risk.enabled') === false && config('app.debug') === false
            && blank(config('infinitepay.api_key')) && blank(config('infinitepay.handle')), 503, 'Unsafe demo configuration.');
        if ($provisioning) {
            abort_if(Customer::query()->whereNull('demo_workspace_id')->exists()
                || User::query()->whereNull('demo_workspace_id')->exists(), 503, 'A dedicated demo database is required.');
        }
    }
}
