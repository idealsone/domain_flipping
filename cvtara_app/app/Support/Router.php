<?php
declare(strict_types=1);

namespace App\Support;

final class Router
{
    private array $routes = [];

    public function get(string $path, array|callable $action, array $middleware = []): self
    {
        return $this->add('GET', $path, $action, $middleware);
    }

    public function post(string $path, array|callable $action, array $middleware = []): self
    {
        return $this->add('POST', $path, $action, $middleware);
    }

    public function add(string $method, string $path, array|callable $action, array $middleware = []): self
    {
        $this->routes[] = compact('method', 'path', 'action', 'middleware');
        return $this;
    }

    public function dispatch(string $method, string $uri): mixed
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';
        foreach ($this->routes as $route) {
            $pattern = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = '#^' . rtrim($pattern, '/') . '$#';
            if ($pattern === '#^$#') {
                $pattern = '#^/$#';
            }
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return $this->run($route, $params);
            }
        }
        return null;
    }

    private function run(array $route, array $params): mixed
    {
        foreach ($route['middleware'] as $mw) {
            $result = (new $mw())->handle();
            if ($result !== true) {
                return $result;
            }
        }
        $action = $route['action'];
        if (is_array($action)) {
            [$class, $method] = $action;
            return (new $class())->{$method}(...array_values($params));
        }
        return $action(...array_values($params));
    }
}
