<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for every business rule violation in the application.
 *
 * The point of a single base class is that the HTTP layer needs exactly one
 * rule to render all of them (see bootstrap/app.php), and callers can catch
 * "the business said no" separately from "the database fell over".
 *
 * Each subclass carries a stable machine-readable code so API clients can
 * branch on the error without string-matching a human message.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * Stable identifier for this failure, e.g. `subscription.invalid_transition`.
     */
    abstract public function errorCode(): string;

    /**
     * HTTP status this failure maps to. 422 covers most business rejections:
     * the request was well-formed but the domain refused it.
     */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * Extra machine-readable context for the client. Never include anything
     * that would leak internals.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
