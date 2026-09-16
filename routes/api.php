<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\V1\AuthController;
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
| Authentication is Sanctum bearer tokens. Two layers guard every route below:
| `auth:sanctum` answers "is this a real caller?" and `abilities:` answers
| "does this credential carry the power for this action?". Ownership — "is this
| your subscription?" — is a third question, answered by the policies. See
| ADR-0006.
|
*/

Route::prefix('v1')->as('api.v1.')->group(function (): void {

    // The only unauthenticated route in the API: you cannot present a token
    // before you have one. Rate limited harder than everything else, because
    // it is the one endpoint where guessing is the attack.
    Route::post('auth/tokens', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.tokens.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'show'])->name('auth.me');
        Route::delete('auth/tokens/current', [AuthController::class, 'destroy'])
            ->name('auth.tokens.destroy');

        Route::middleware('abilities:'.TokenAbility::PlansRead->value)->group(function (): void {
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
        });

        Route::middleware('abilities:'.TokenAbility::SubscriptionsRead->value)->group(function (): void {
            Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])
                ->name('subscriptions.show');

            // A preview writes nothing, so it needs only read scope — even
            // though it sits under the plan-change feature.
            Route::post('subscriptions/{subscription}/plan/preview', [SubscriptionPlanController::class, 'preview'])
                ->name('subscriptions.plan.preview');
        });

        Route::middleware('abilities:'.TokenAbility::SubscriptionsWrite->value)->group(function (): void {
            Route::post('subscriptions', [SubscriptionController::class, 'store'])
                ->name('subscriptions.store');

            Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
                ->name('subscriptions.destroy');

            Route::put('subscriptions/{subscription}/plan', [SubscriptionPlanController::class, 'update'])
                ->name('subscriptions.plan.update');
        });
    });
});
