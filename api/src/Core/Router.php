<?php

declare(strict_types=1);

namespace Velora\Core;

use Velora\Core\Exceptions\ApiException;
use Velora\Core\Exceptions\MethodNotAllowedException;
use Velora\Core\Exceptions\NotFoundException;

/**
 * Tiny dependency-free router.
 * Routes map to [Controller::class, 'method'] and may be wrapped in middleware
 * arrays (e.g. the JWT auth middleware).
 */
final class Router
{
    /** @var array<string, array<int, array{path:string, method:string, handler:callable|array, middleware:array}>> */
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[$method][] = [
            'path' => rtrim($path, '/') ?: '/',
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(Request $request): mixed
    {
        $method = $request->method;
        $path = rtrim($request->path, '/') ?: '/';

        $candidates = $this->routes[$method] ?? [];
        if ($candidates === []) {
            // Let OPTIONS preflight through for CORS.
            if ($method === 'OPTIONS') {
                Response::corsPreflight();
            }
            throw new MethodNotAllowedException();
        }

        foreach ($candidates as $route) {
            $params = $this->match($route['path'], $path);
            if ($params === null) {
                continue;
            }

            foreach ($route['middleware'] as $middleware) {
                $middleware($request, $params);
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $methodName] = $handler;
                $instance = new $class();
                return $instance->$methodName($request, $params);
            }
            return $handler($request, $params);
        }

        throw new NotFoundException('Route not found: ' . $method . ' ' . $path);
    }

    /**
     * Very small route matcher: supports {param} segments.
     *
     * @return array<string,string>|null
     */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        $patternParts = explode('/', $pattern);
        $pathParts = explode('/', $path);
        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $name = trim($part, '{}');
                $params[$name] = urldecode($pathParts[$i]);
            } elseif ($part !== $pathParts[$i]) {
                return null;
            }
        }
        return $params;
    }
}
