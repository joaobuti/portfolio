<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\DemoPruner;
use Illuminate\Console\Command;

final class PruneDemoWorkspacesCommand extends Command
{
    protected $signature = 'demo:prune';

    protected $description = 'Remove expired synthetic demo generations (never normal customers)';

    public function handle(DemoPruner $pruner): int
    {
        $this->components->info('Removed '.$pruner->prune().' expired demo workspaces.');

        return self::SUCCESS;
    }
}
