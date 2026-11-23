<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use Illuminate\Routing\Route;

it('documents every public API method, route parameter and required ability', function (): void {
    $contract = json_decode(file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $documented = [];
    foreach ($contract['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $documented[] = strtoupper($method).' '.$path;
            expect($operation['operationId'])->not->toBeEmpty()
                ->and($operation['responses'])->not->toBeEmpty();
        }
    }
    $actual = [];
    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        /** @var Route $route */
        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $actual[] = $method.' '.$path;
            $operation = $contract['paths'][$path][strtolower($method)];
            foreach ($route->middleware() as $middleware) {
                if (str_starts_with($middleware, 'abilities:')) {
                    $abilities = $operation['x-token-abilities'] ?? [$operation['x-token-ability'] ?? ''];
                    expect($abilities)->toContain(substr($middleware, strlen('abilities:')));
                }
            }
            if (in_array('auth:sanctum', $route->middleware(), true)) {
                $security = str_starts_with($path, '/demo/') ? [['cookieAuth' => []]]
                    : (in_array($path, ['/metrics', '/auth/tokens/current'], true) ? [['bearerAuth' => []]]
                        : [['bearerAuth' => []], ['cookieAuth' => []]]);
                expect($operation['security'] ?? $contract['security'])->toBe($security);
            } else {
                expect($operation['security'])->toBe($path === '/demo/profile' ? [['cookieAuth' => []]] : []);
            }
            preg_match_all('/\{([^}]+)\}/', $path, $parameters);
            $documentedParameters = array_column(array_filter($operation['parameters'] ?? [], fn (array $p): bool => $p['in'] === 'path'), 'name');
            expect($documentedParameters)->toBe($parameters[1]);
        }
    }
    sort($actual);
    sort($documented);
    expect($documented)->toBe($actual)
        ->and($contract['components']['schemas']['Login']['properties']['abilities']['items']['enum'])
        ->toBe(array_column(TokenAbility::cases(), 'value'));
});
