<?php
declare(strict_types=1);

namespace BharatSeo\Core;

/**
 * Configuration loader. The config file is a PHP file returning an array and is
 * looked up OUTSIDE the public web root first.
 */
final class Config
{
    private array $data;
    private ?string $path;

    public function __construct(array $data, ?string $path = null)
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
        $this->path = $path;
    }

    public static function defaults(): array
    {
        return [
            'app' => [
                'name' => 'NoidaRankers',
                'url' => '',
                'env' => 'production',
                'debug' => false,
                'timezone' => 'Asia/Kolkata',
                'key' => '',
                'pretty_urls' => true,
                'demo_mode' => false,
            ],
            'db' => [
                'host' => 'localhost',
                'port' => 3306,
                'socket' => '',
                'name' => '',
                'user' => '',
                'pass' => '',
                'charset' => 'utf8mb4',
            ],
            'paths' => [
                'storage' => APP_ROOT . '/storage',
            ],
            'session' => [
                'name' => 'BSEOSESSID',
                'idle_minutes' => 60,
                'absolute_hours' => 12,
                'secure_cookie' => 'auto',
            ],
            'cron' => [
                'web_trigger_enabled' => false,
                'web_trigger_secret' => '',
            ],
            'security' => [
                'trusted_proxies' => [],
            ],
        ];
    }

    /** Candidate config locations in priority order (outside web root first). */
    public static function candidatePaths(): array
    {
        $paths = [];
        $env = getenv('BHARATSEO_CONFIG');
        if (is_string($env) && $env !== '') {
            $paths[] = $env;
        }
        $paths[] = dirname(APP_ROOT) . '/bharatseo-private/config.php';
        $paths[] = APP_ROOT . '/config/config.php';
        return $paths;
    }

    public static function locate(): ?string
    {
        foreach (self::candidatePaths() as $p) {
            if (is_file($p) && is_readable($p)) {
                return $p;
            }
        }
        return null;
    }

    public static function load(): ?self
    {
        $path = self::locate();
        if ($path === null) {
            return null;
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('Configuration file must return an array.');
        }
        return new self($data, $path);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $node = &$this->data;
        foreach (explode('.', $key) as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function storagePath(string $sub = ''): string
    {
        $base = rtrim((string)$this->get('paths.storage'), '/');
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }

    public function isProduction(): bool
    {
        return $this->get('app.env') === 'production';
    }

    public function all(): array
    {
        return $this->data;
    }

    /** Render a config array as a PHP file. */
    public static function export(array $data): string
    {
        return "<?php\n// Bharat SEO configuration. Keep this file OUTSIDE the public web root.\n// Generated " . gmdate('c') . "\nreturn " . var_export($data, true) . ";\n";
    }
}
