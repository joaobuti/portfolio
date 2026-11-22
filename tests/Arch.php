<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| Conventions that are documented but not enforced are conventions that decay.
| These run in CI alongside everything else, so a boundary can only be crossed
| deliberately — by editing this file, in a diff a reviewer will see.
|
| The first three are the ones that carry weight: they are the difference
| between claiming the domain is framework-free and proving it.
|
*/

arch('money, enums and DTOs never touch the framework')
    ->expect([App\Support\Money::class, 'App\Enums', 'App\DTOs'])
    ->not->toUse('Illuminate');

arch('the proration calculator depends on nothing but PHP and Money')
    ->expect(App\Services\Subscriptions\ProrationCalculator::class)
    ->not->toUse(['Illuminate', 'App\Models', 'App\Http']);

arch('services never reach back into the HTTP layer')
    ->expect('App\Services')
    ->not->toUse('App\Http');

arch('controllers delegate rather than query')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        Illuminate\Support\Facades\DB::class,
        Illuminate\Support\Facades\Cache::class,
        Illuminate\Database\Eloquent\Builder::class,
    ]);

arch('form requests validate and nothing else')
    ->expect('App\Http\Requests')
    ->not->toUse(['App\Services', Illuminate\Support\Facades\DB::class]);

arch('events carry data, they do not do work')
    ->expect('App\Events')
    ->not->toUse(['App\Services', Illuminate\Support\Facades\DB::class]);

arch('jobs are queued, never accidentally synchronous')
    ->expect('App\Jobs')
    ->toImplement(Illuminate\Contracts\Queue\ShouldQueue::class);

arch('no debugging statements survive')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('configuration is read from config, never from env')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('config');

arch('everything declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('value objects and DTOs are immutable')
    ->expect(['App\DTOs', App\Support\Money::class])
    ->toBeReadonly();

arch('enums are string backed so they persist and read well')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();

arch('classes are final unless designed for extension')
    ->expect('App')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        App\Exceptions\DomainException::class,
        App\Http\Controllers\Controller::class,
        App\Providers\AppServiceProvider::class,
    ]);

arch('every domain exception is renderable by the one handler rule')
    ->expect('App\Exceptions')
    ->toExtend(App\Exceptions\DomainException::class)
    ->ignoring(App\Exceptions\DomainException::class);

arch()->preset()->php();
arch()->preset()->security();
