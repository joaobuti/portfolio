<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Exceptions\DomainException;

/**
 * The credentials did not authenticate.
 *
 * One exception for "no such email" and "wrong password", with one message.
 * Distinguishing them turns the login endpoint into an account enumeration
 * oracle: an attacker learns which addresses are registered by reading the
 * error, without ever guessing a password.
 *
 * 401 rather than the base class's 422 — this is a failure to authenticate,
 * not a business rule rejecting a well-formed request.
 */
final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('These credentials do not match our records.');
    }

    public function errorCode(): string
    {
        return 'auth.invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
