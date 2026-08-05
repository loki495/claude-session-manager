<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Deliberately simple - exact-string route matching, no groups/middleware/
 * path parameters yet (every dynamic value like a session name is already
 * a query param, not a path segment - see routes.php). match() is a pure
 * lookup with no output of its own, so the caller (public/index.php)
 * decides what "no match" means: fall through to an old not-yet-migrated
 * flat file during the router rollout, or a hard 404 once migration is
 * complete.
 */
class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> */
    private array $routes = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $path, array $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $path, array $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /** @return array{0: class-string, 1: string}|null */
    public function match(string $method, string $path): ?array
    {
        return $this->routes[$method][$path] ?? null;
    }
}
