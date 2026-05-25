<?php

declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function __construct(private readonly string $method, private readonly string $path)
    {
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(): array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $this->method) {
                continue;
            }

            $matches = [];
            if (preg_match($route['pattern'], $this->path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $name) {
                $params[$name] = $matches[$name];
            }

            return $route['handler']($params);
        }

        throw new HttpException(404, 'not_found', '找不到指定路由');
    }

    private function add(string $method, string $path, callable $handler): void
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)}/', $path, $paramMatches);
        $params = $paramMatches[1];
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)}/', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }
}
