<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Auth\LoginData;
use App\Enums\TokenAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\IssuedTokenResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use App\Services\Auth\TokenIssuer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issuing and disposing of access tokens.
 *
 * Thin, like every controller here: translate, delegate, respond. The two
 * collaborators are both abstractions — the service for "are these credentials
 * good?", the issuer for "throw this token away" — so neither Sanctum nor a
 * password hash appears in this file.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly TokenIssuer $tokens,
    ) {}

    /**
     * Exchange credentials for a bearer token.
     *
     * 201, not 200: this creates a token that did not exist before and that
     * the caller is now responsible for.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        abort_if(config('demo.enabled') === true || \Illuminate\Support\Facades\Auth::guard('web')->check(), 403);
        $token = $this->authentication->authenticate(new LoginData(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            tokenName: $request->string('token_name', 'api')->toString(),
            requestedAbilities: TokenAbility::fromValues(
                // Validated as a list of enum-backed strings by LoginRequest;
                // filtered again here so the DTO's contract holds no matter
                // which caller builds it.
                array_values(array_filter($request->array('abilities'), is_string(...))),
            ),
        ));

        return IssuedTokenResource::make($token)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Who the bearer of this token is.
     */
    public function show(Request $request): UserResource
    {
        return UserResource::make($this->currentUser($request)->load('customer'));
    }

    /**
     * Revoke the token this request was made with.
     *
     * 204 whether or not a row was deleted. "Log me out" is a request for an
     * end state, and reporting 404 because a concurrent logout got there first
     * would be technically accurate and operationally useless.
     */
    public function destroy(Request $request): Response
    {
        abort_if(app(\App\Services\Auth\EffectiveAbilities::class)->isSession($this->currentUser($request)), 403);
        $this->tokens->revokeCurrent($this->currentUser($request));

        return response()->noContent();
    }

    /**
     * The authenticated user, as our own type.
     *
     * Both actions here sit behind `auth:sanctum`, so the null branch is
     * unreachable — but it is expressed as a refusal rather than an `assert()`
     * because assertions are compiled out in production, and a guard that
     * disappears when it matters most is not a guard.
     *
     * @throws AuthenticationException
     */
    private function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
