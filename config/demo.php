<?php

declare(strict_types=1);

return [
    // Requires a dedicated, empty database and APP_ENV=demo. Never a browser flag.
    'enabled' => (bool) env('DEMO_ENABLED', false),
    'ttl_minutes' => max(5, min(1440, (int) env('DEMO_TTL_MINUTES', 60))),
    'max_workspaces' => max(1, min(1000, (int) env('DEMO_MAX_WORKSPACES', 100))),
];
