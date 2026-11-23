<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InfinitePayWebhookController;
use App\Http\Controllers\Api\V1\InvoiceCheckoutController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoicePaymentCheckController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SubscriptionPlanController;
use App\Http\Middleware\DashboardAccess;
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

    Route::post('demo/session', [App\Http\Controllers\Api\V1\DemoController::class, 'store'])
        ->middleware(['web', 'throttle:demo'])->block(10, 10)->name('demo.session');
    Route::post('demo/profile', [App\Http\Controllers\Api\V1\DemoController::class, 'profile'])
        ->middleware(['web', 'throttle:demo'])->block(10, 10)->name('demo.profile');

    Route::post('auth/session', [SessionController::class, 'store'])->middleware(['web', 'throttle:login'])->name('session.store');
    Route::get('auth/csrf', [SessionController::class, 'csrf'])->middleware('web')->name('session.csrf');
    Route::delete('auth/session', [SessionController::class, 'destroy'])->middleware('web')->name('session.destroy');

    Route::post('webhooks/infinitepay', InfinitePayWebhookController::class)
        ->middleware('throttle:infinitepay-webhooks')->name('webhooks.infinitepay');

    // Token issuance is public: you cannot present a token
    // before you have one. Rate limited harder than everything else, because
    // it is the one endpoint where guessing is the attack.
    Route::post('auth/tokens', [AuthController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.tokens.store');

    Route::middleware(['auth:sanctum', DashboardAccess::class, 'throttle:api'])->group(function (): void {
        Route::post('demo/invoices/{invoice}/payment', App\Http\Controllers\Api\V1\DemoPaymentController::class)
            ->middleware(['abilities:'.TokenAbility::InvoicesWrite->value, 'throttle:api'])->name('demo.payment');
        Route::get('subscriptions', [DashboardController::class, 'subscriptions'])
            ->middleware('abilities:'.TokenAbility::SubscriptionsRead->value)->name('subscriptions.index');
        Route::middleware('abilities:'.TokenAbility::CustomersRead->value)->group(function (): void {
            Route::get('customers', [DashboardController::class, 'customers'])->name('customers.index');
            Route::get('customers/{customer}', [DashboardController::class, 'customer'])->name('customers.show');
            Route::get('customers/{customer}/subscriptions', [DashboardController::class, 'customerSubscriptions'])
                ->middleware('abilities:'.TokenAbility::SubscriptionsRead->value)->name('customers.subscriptions');
        });
        Route::get('invoices', [DashboardController::class, 'invoices'])
            ->middleware('abilities:'.TokenAbility::InvoicesRead->value)->name('invoices.all');
        Route::get('metrics', MetricsController::class)
            ->middleware(['abilities:'.TokenAbility::MetricsRead->value, 'throttle:api'])->name('metrics');
        Route::middleware('abilities:'.TokenAbility::InvoicesRead->value)->group(function (): void {
            Route::get('subscriptions/{subscription}/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        });
        Route::middleware(['abilities:'.TokenAbility::InvoicesWrite->value, 'throttle:api'])->group(function (): void {
            Route::post('invoices/{invoice}/checkout', [InvoiceCheckoutController::class, 'store'])->name('invoices.checkout');
            Route::post('invoices/{invoice}/payment-check', [InvoicePaymentCheckController::class, 'store'])->name('invoices.payment-check');
        });
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
