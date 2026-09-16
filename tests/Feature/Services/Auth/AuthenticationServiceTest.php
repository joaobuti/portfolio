<?php

declare(strict_types=1);

use App\DTOs\Auth\LoginData;
use App\Enums\TokenAbility;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\AuthenticationService;
use Database\Factories\UserFactory;

beforeEach(function (): void {
    freezeClockAt('2026-06-16 09:00:00');

    $this->service = app(AuthenticationService::class);
    $this->customer = Customer::factory()->create();
});

describe('credentials', function (): void {
    it('issues a token for a valid pair', function (): void {
        $user = User::factory()->belongingTo($this->customer)->create(['email' => 'ana@example.test']);

        $token = $this->service->authenticate(new LoginData('ana@example.test', UserFactory::PASSWORD));

        expect($token->plainTextToken)->not->toBeEmpty()
            ->and($user->fresh()?->tokens()->count())->toBe(1);
    });

    it('refuses a wrong password', function (): void {
        User::factory()->belongingTo($this->customer)->create(['email' => 'ana@example.test']);

        $this->service->authenticate(new LoginData('ana@example.test', 'wrong'));
    })->throws(InvalidCredentialsException::class);

    it('refuses an unknown email', function (): void {
        $this->service->authenticate(new LoginData('nobody@example.test', UserFactory::PASSWORD));
    })->throws(InvalidCredentialsException::class);
});

describe('privilege escalation', function (): void {
    it('cannot be widened past the role ceiling', function (): void {
        // The central guarantee. A customer asking for every ability in the
        // enum gets only the ones their role defines — the request is
        // intersected, never unioned, so no endpoint can forget to check.
        User::factory()->belongingTo($this->customer)->create(['email' => 'ana@example.test']);

        $token = $this->service->authenticate(new LoginData(
            email: 'ana@example.test',
            password: UserFactory::PASSWORD,
            requestedAbilities: TokenAbility::cases(),
        ));

        expect($token->abilities)->toBe(App\Enums\UserRole::Customer->defaultAbilities());
    });

    it('narrows when asked for less', function (): void {
        User::factory()->belongingTo($this->customer)->create(['email' => 'ana@example.test']);

        $token = $this->service->authenticate(new LoginData(
            email: 'ana@example.test',
            password: UserFactory::PASSWORD,
            requestedAbilities: [TokenAbility::PlansRead],
        ));

        expect($token->abilities)->toBe([TokenAbility::PlansRead]);
    });
});

describe('expiry', function (): void {
    it('stamps an absolute expiry from config', function (): void {
        config()->set('sanctum.expiration', 720);
        User::factory()->belongingTo($this->customer)->create(['email' => 'ana@example.test']);

        $token = $this->service->authenticate(new LoginData('ana@example.test', UserFactory::PASSWORD));

        expect($token->expiresAt?->format('Y-m-d H:i'))->toBe('2026-06-16 21:00');
    });

    it('issues a non-expiring token when config says so, and says so', function (): void {
        config()->set('sanctum.expiration');
        User::factory()->belongingTo($this->customer)->create(['email' => 'ana@example.test']);

        $token = $this->service->authenticate(new LoginData('ana@example.test', UserFactory::PASSWORD));

        expect($token->expiresAt)->toBeNull();
    });
});
