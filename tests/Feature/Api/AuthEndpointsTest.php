<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Database\Factories\UserFactory;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 09:00:00');

    $this->customer = Customer::factory()->create();
    $this->user = User::factory()->belongingTo($this->customer)->create([
        'email' => 'ana@example.test',
    ]);
});

describe('POST /api/v1/auth/tokens', function (): void {
    it('exchanges credentials for a bearer token', function (): void {
        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'abilities', 'expires_at']]);

        expect($this->user->fresh()?->tokens()->count())->toBe(1);
    });

    it('records when the account last authenticated', function (): void {
        expect($this->user->last_login_at)->toBeNull();

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
        ])->assertCreated();

        expect($this->user->fresh()?->last_login_at?->toDateTimeString())
            ->toBe('2026-06-16 09:00:00');
    });

    it('gives the same answer for a wrong password and an unknown email', function (): void {
        // Account enumeration: if these two differed, the login endpoint would
        // tell an attacker which addresses are registered without them ever
        // guessing a password.
        $wrongPassword = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => 'not-the-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'nobody@example.test',
            'password' => UserFactory::PASSWORD,
        ]);

        $wrongPassword->assertUnauthorized()
            ->assertJsonPath('error.code', 'auth.invalid_credentials');

        expect($unknownEmail->status())->toBe($wrongPassword->status())
            ->and($unknownEmail->json('error'))->toBe($wrongPassword->json('error'));
    });

    it('never returns the password hash', function (): void {
        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
        ]);

        expect(json_encode($response->json()))->not->toContain('$2y$');
    });

    it('validates the payload before touching the database', function (): void {
        $this->postJson('/api/v1/auth/tokens', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });
});

describe('token abilities', function (): void {
    it('grants the role ceiling when nothing is requested', function (): void {
        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
        ]);

        expect($response->json('data.abilities'))
            ->toBe(TokenAbility::toValues(UserRole::Customer->defaultAbilities()));
    });

    it('narrows a token to what was asked for', function (): void {
        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
            'abilities' => [TokenAbility::PlansRead->value],
        ]);

        expect($response->json('data.abilities'))->toBe([TokenAbility::PlansRead->value]);
    });

    it('rejects an ability that is not in the enum', function (): void {
        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
            'abilities' => ['everything:always'],
        ])->assertUnprocessable()->assertJsonValidationErrors('abilities.0');
    });

    it('stores the abilities on the token itself, not just in the response', function (): void {
        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
            'abilities' => [TokenAbility::SubscriptionsRead->value],
        ])->assertCreated();

        $token = PersonalAccessToken::query()->firstOrFail();

        expect($token->abilities)->toBe([TokenAbility::SubscriptionsRead->value]);
    });
});

describe('GET /api/v1/auth/me', function (): void {
    it('describes the bearer of the token', function (): void {
        actingAsCustomerUser($this->customer);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'customer')
            ->assertJsonPath('data.customer.uuid', $this->customer->uuid);
    });

    it('refuses an anonymous caller', function (): void {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    });

    it('refuses a token that does not exist', function (): void {
        $this->withHeader('Authorization', 'Bearer 1|totally-made-up')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    });
});

describe('DELETE /api/v1/auth/tokens/current', function (): void {
    it('revokes the token it was called with', function (): void {
        $plainText = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
        ])->json('data.token');

        expect(PersonalAccessToken::query()->count())->toBe(1);

        $this->withHeader('Authorization', "Bearer {$plainText}")
            ->deleteJson('/api/v1/auth/tokens/current')
            ->assertNoContent();

        // Asserted against the database rather than by replaying the token:
        // Laravel reuses one application instance across requests within a
        // test, so the auth guard still holds the user it resolved a moment
        // ago. The row being gone is the real behaviour; a second request in
        // the same test would be measuring the test harness.
        expect(PersonalAccessToken::query()->count())->toBe(0);
    });

    it('leaves other tokens of the same user alone', function (): void {
        $first = $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
            'token_name' => 'laptop',
        ])->json('data.token');

        $this->postJson('/api/v1/auth/tokens', [
            'email' => 'ana@example.test',
            'password' => UserFactory::PASSWORD,
            'token_name' => 'phone',
        ])->assertCreated();

        $this->withHeader('Authorization', "Bearer {$first}")
            ->deleteJson('/api/v1/auth/tokens/current')
            ->assertNoContent();

        // Logging out on one device must not sign you out of the others.
        expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['phone']);
    });
});
