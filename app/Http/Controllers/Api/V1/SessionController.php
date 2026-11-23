<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SessionController extends Controller
{
    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function store(LoginRequest $request, AuthenticationService $authentication): UserResource
    {
        abort_if(config('demo.enabled') === true, 404);
        $user = $authentication->verifyCredentials($request->string('email')->toString(), $request->string('password')->toString());
        abort_if($user->demo_workspace_id !== null, 403);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();
        $request->setUserResolver(fn (): \App\Models\User => $user);

        return UserResource::make($user->load('customer'));
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return response()->noContent();
    }
}
