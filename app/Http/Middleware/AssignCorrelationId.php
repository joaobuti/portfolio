<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id and makes it follow the work wherever it goes.
 *
 * Laravel's Context is automatically serialised onto queued jobs and injected
 * into log lines, so one header set here shows up unchanged in the worker that
 * renews the subscription minutes later — and, once the Python risk worker
 * lands, in the message payload it consumes.
 *
 * Accepting an inbound `X-Correlation-Id` matters as much as generating one:
 * it is what lets a caller stitch our logs to theirs.
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header(self::HEADER) ?? (string) Str::uuid();

        Context::add('correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
