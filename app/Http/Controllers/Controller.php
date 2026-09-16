<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * The base every API controller extends.
 *
 * It carries exactly one thing: `authorize()`, for the actions that have no
 * FormRequest to host the check. Everything expressed as a FormRequest keeps
 * authorising there — this is the fallback for a route like `show` whose input
 * is a single bound model and which would otherwise need a request class that
 * validates nothing.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
