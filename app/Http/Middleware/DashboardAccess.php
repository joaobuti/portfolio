<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\DemoWorkspace;
use App\Models\User;
use App\Services\Auth\EffectiveAbilities;
use App\Support\Clock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class DashboardAccess
{
    public function __construct(private EffectiveAbilities $abilities, private Clock $clock) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($user->demo_workspace_id !== null) {
            abort_unless(config('demo.enabled') === true && $this->abilities->isSession($user)
                && $request->hasSession() && $request->session()->get('demo_workspace_id') === $user->demo_workspace_id
                && DemoWorkspace::query()->whereKey($user->demo_workspace_id)->where('expires_at', '>', $this->clock->now())->exists(), 401);
        } else {
            abort_if(config('demo.enabled') === true, 403);
        }
        // Preview is POST and persists a quote: internal browser sessions cannot
        // invoke it just because the API's existing preview scope is read-only.
        abort_if($this->abilities->isSession($user) && $user->role->accessesAnyCustomer() && ! $request->isMethodSafe(), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
