<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Versioned from the first commit. Adding /v1 later means either breaking
| every existing client or maintaining an unversioned alias forever.
|
| Subscriptions are addressed by UUID and plans by code — see the models'
| getRouteKeyName(). Auto-increment ids never appear in a URL.
|
*/

Route::prefix('v1')->as('api.v1.')->group(function (): void {
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');

    Route::post('subscriptions', [SubscriptionController::class, 'store'])
        ->name('subscriptions.store');

    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])
        ->name('subscriptions.show');

    Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
        ->name('subscriptions.destroy');

    Route::post('subscriptions/{subscription}/plan/preview', [SubscriptionPlanController::class, 'preview'])
        ->name('subscriptions.plan.preview');

    Route::put('subscriptions/{subscription}/plan', [SubscriptionPlanController::class, 'update'])
        ->name('subscriptions.plan.update');
});
