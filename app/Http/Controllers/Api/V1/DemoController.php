<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Demo\DemoProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class DemoController extends Controller
{
    public function store(Request $request, DemoProvisioner $demo): UserResource
    {
        $request->validate(['scenario' => ['required', Rule::in(['active', 'pending', 'change'])],
            'profile' => ['required', Rule::in(['customer', 'staff'])]]);
        $previous = $request->session()->get('demo_workspace_id');
        $identity = $demo->create($request->string('scenario')->toString(), $request->string('profile')->toString(),
            is_int($previous) ? $previous : null);

        return $this->signIn($request, $identity['workspace']->id, $request->string('profile')->toString(), $demo);
    }

    public function profile(Request $request, DemoProvisioner $demo): UserResource
    {
        $request->validate(['profile' => ['required', Rule::in(['customer', 'staff'])]]);
        $id = $request->session()->get('demo_workspace_id');
        abort_unless(is_int($id), 401);

        return $this->signIn($request, $id, $request->string('profile')->toString(), $demo);
    }

    private function signIn(Request $request, int $id, string $profile, DemoProvisioner $demo): UserResource
    {
        $workspace = $demo->workspace($id);
        $user = $demo->identity($id, $profile);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put('demo_workspace_id', $id);
        Auth::forgetGuards();
        $request->setUserResolver(fn (): \App\Models\User => $user);

        return UserResource::make($user->load('customer'))->additional(['demo' => [
            'workspace' => $workspace->uuid, 'scenario' => $workspace->scenario,
            'expires_at' => $workspace->expires_at->toIso8601String(), 'simulated' => true]]);
    }
}
