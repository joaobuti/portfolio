<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class DashboardCatalog
{
    /** @return LengthAwarePaginator<int, Customer> */
    public function customers(User $user, string $search): LengthAwarePaginator
    {
        return Customer::query()->where('demo_workspace_id', $user->demo_workspace_id)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')))
            ->orderBy('id')->paginate(20)->withQueryString();
    }

    /** @return LengthAwarePaginator<int, Subscription> */
    public function subscriptions(User $user, ?Customer $customer = null): LengthAwarePaginator
    {
        return Subscription::query()->with(['plan', 'pendingPlan'])
            ->whereIn('customer_id', $this->visibleCustomers($user)->select('id'))
            ->when($customer instanceof Customer, fn (Builder $query) => $query->where('customer_id', $customer?->id))
            ->latest('id')->paginate(20);
    }

    /** @return LengthAwarePaginator<int, Invoice> */
    public function invoices(User $user, string $status, string $customerUuid): LengthAwarePaginator
    {
        return Invoice::query()->with(['checkout', 'customer'])
            ->whereIn('customer_id', $this->visibleCustomers($user)
                ->when($customerUuid !== '', fn (Builder $query) => $query->where('uuid', $customerUuid))->select('id'))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();
    }

    /** @return Builder<Customer> */
    private function visibleCustomers(User $user): Builder
    {
        return Customer::query()->where('demo_workspace_id', $user->demo_workspace_id)
            ->when(! $user->role->accessesAnyCustomer(), fn (Builder $query) => $query->whereKey($user->customer_id));
    }
}
