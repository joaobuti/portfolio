<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\Subscriptions\PlanCatalog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanController extends Controller
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection($this->catalog->active());
    }

    /**
     * Bound by `code`, per Plan::getRouteKeyName().
     */
    public function show(Plan $plan): PlanResource
    {
        return PlanResource::make($plan);
    }
}
