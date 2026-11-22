<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

final readonly class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->canActOnCustomerId($invoice->customer_id);
    }

    public function checkout(User $user, Invoice $invoice): bool
    {
        return $user->canActOnCustomerId($invoice->customer_id);
    }
}
