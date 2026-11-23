<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DashboardQueryRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
use App\Models\User;
use App\Services\Dashboard\DashboardCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DashboardController extends Controller
{
    public function customers(DashboardQueryRequest $request, DashboardCatalog $catalog): AnonymousResourceCollection
    {
        return CustomerResource::collection($catalog->customers($this->user($request), $request->string('q')->toString()));
    }

    public function customer(DashboardQueryRequest $request, Customer $customer): CustomerResource
    {
        abort_unless($this->user($request)->canActOnCustomer($customer), 403);

        return CustomerResource::make($customer);
    }

    public function subscriptions(DashboardQueryRequest $request, DashboardCatalog $catalog): AnonymousResourceCollection
    {
        return SubscriptionResource::collection($catalog->subscriptions($this->user($request)));
    }

    public function customerSubscriptions(DashboardQueryRequest $request, Customer $customer, DashboardCatalog $catalog): AnonymousResourceCollection
    {
        $user = $this->user($request);
        abort_unless($user->canActOnCustomer($customer), 403);

        return SubscriptionResource::collection($catalog->subscriptions($user, $customer));
    }

    public function invoices(DashboardQueryRequest $request, DashboardCatalog $catalog): AnonymousResourceCollection
    {
        return InvoiceResource::collection($catalog->invoices($this->user($request),
            $request->string('status')->toString(), $request->string('customer')->toString()));
    }

    private function user(DashboardQueryRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
