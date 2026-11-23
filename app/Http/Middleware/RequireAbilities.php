<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\EffectiveAbilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireAbilities
{
    public function __construct(private EffectiveAbilities $abilities) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $granted = $this->abilities->forUser($user);
        foreach ($required as $ability) {
            abort_unless(in_array($ability, $granted, true), 403);
        }

        return $next($request);
    }
}
