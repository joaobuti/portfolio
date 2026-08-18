<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
|
| There is no web UI. This service is an API, and the root route says so
| rather than serving a framework welcome page that would suggest otherwise.
|
| The dashboard, when it arrives, will be a separate TypeScript application
| consuming /api/v1 — not Blade views bolted on here.
|
*/

Route::get('/', fn (): JsonResponse => response()->json([
    'service' => config('app.name'),
    'api' => url('/api/v1/plans'),
    'health' => url('/up'),
    'docs' => 'docs/ARCHITECTURE.md',
]));
