<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Observability\OperationalMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MetricsController extends Controller
{
    public function __invoke(Request $request, OperationalMetrics $metrics): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->role->accessesAnyCustomer(), 403);

        return response($metrics->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
