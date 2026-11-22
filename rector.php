<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Set\ValueObject\SetList;

/**
 * Rector runs in CI in --dry-run mode, so it reports rather than rewrites.
 *
 * That is the important part: an automated refactoring tool that edits code
 * unattended will eventually make a change nobody reviewed. Here it acts as a
 * reviewer that never gets tired, and a human applies what it suggests.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php83: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    ->withSkip([
        // Eloquent supplies the model to observer hooks; retain that contract.
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__.'/app/Observers/PlanObserver.php'],
        // Laravel's magic surface confuses these; they produce churn, not value.
        __DIR__.'/app/Http/Resources',
    ]);
