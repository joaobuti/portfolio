<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Demo\DemoConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class DemoBoundary
{
    public function __construct(private DemoConfiguration $configuration) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('demo.enabled') === true || $request->is('api/v1/demo/*')) {
            $this->configuration->ensureEnabled();
            abort_if($request->is('api/v1/auth/tokens', 'api/v1/webhooks/*',
                'api/v1/invoices/*/checkout', 'api/v1/invoices/*/payment-check') || $request->bearerToken() !== null
                || ($request->is('api/v1/auth/session') && $request->isMethod('POST')), 404);
        }

        return $next($request);
    }
}
