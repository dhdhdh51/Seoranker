<?php
declare(strict_types=1);

namespace Flatmates\Http;

final class Router
{
    /** @var array<int,array{method:string, regex:string, handler:array, options:array, params:string[]}> */
    private array $routes = [];

    private const PARAM_PATTERNS = [
        'id' => '\d{1,10}',
        'uid' => '\d{1,10}',
        'token' => '[a-f0-9]{64}',
        'type' => '[a-z_]{2,30}',
        'pid' => '\d{1,10}',
        'step' => '[1-4]',
        'slug' => '[a-z0-9][a-z0-9-]{0,169}',
        'city' => '[a-z0-9][a-z0-9-]{1,119}',
        'college' => '[a-z0-9][a-z0-9-]{1,199}',
        'dir' => 'photos|listings',
        'file' => '[a-f0-9]{32}(?:-sm)?\.jpg',
    ];

    public function get(string $path, array $handler, array $options = []): void
    {
        $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, array $handler, array $options = []): void
    {
        $this->add('POST', $path, $handler, $options);
    }

    public function add(string $method, string $path, array $handler, array $options): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-z]+)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '(' . (self::PARAM_PATTERNS[$m[1]] ?? '[^/]+') . ')';
        }, $path);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'options' => $options + ['auth' => true, 'roles' => [], 'csrf' => true, 'module' => null],
            'params' => $params,
        ];
    }

    /**
     * @return array{route:?array, args:array, allowed:string[]}
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                if ($r['method'] !== $method) {
                    $allowed[] = $r['method'];
                    continue;
                }
                $args = [];
                foreach ($r['params'] as $i => $name) {
                    $args[$name] = in_array($name, ['id', 'uid', 'pid', 'step'], true) ? (int)$m[$i + 1] : $m[$i + 1];
                }
                return ['route' => $r, 'args' => $args, 'allowed' => []];
            }
        }
        return ['route' => null, 'args' => [], 'allowed' => $allowed];
    }
}
