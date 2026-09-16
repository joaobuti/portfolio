<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A correlation id is attached to every request and propagated onto
        // every queued job and log line. It is what makes a single customer
        // action traceable from the HTTP edge, through Redis, into the worker.
        $middleware->api(prepend: [
            App\Http\Middleware\AssignCorrelationId::class,
        ]);

        // Sanctum's ability guards, aliased so routes can read
        // `abilities:subscriptions:write` instead of a class name. `abilities`
        // requires all of them; `ability` requires any one.
        $middleware->alias([
            'abilities' => Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'ability' => Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // One rule renders every business rule violation in the application.
        // Each exception carries its own status, stable code and context, so
        // adding a new one never means touching this file.
        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'context' => $e->context(),
                ],
            ], $e->httpStatus());
        });
    })->create();
