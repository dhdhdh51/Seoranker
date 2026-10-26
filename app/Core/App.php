<?php
declare(strict_types=1);

namespace Flatmates\Core;

use Flatmates\Security\Crypto;
use Flatmates\Services\SettingsService;

/** Minimal service container. */
final class App
{
    private static ?App $instance = null;
    public Config $config;
    private ?Database $db = null;
    private array $services = [];
    private array $factories = [];

    private function __construct(Config $config)
    {
        $this->config = $config;
    }

    public static function boot(bool $requireConfig = true, ?array $override = null): self
    {
        if ($override !== null) {
            $config = new Config($override, null);
        } else {
            $config = Config::load();
            if ($config === null) {
                if ($requireConfig) {
                    throw new NotInstalledException('Student Flatmate Finder is not installed yet.');
                }
                $config = new Config([], null);
            }
        }
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        self::$instance = new self($config);
        ErrorHandler::register(self::$instance);
        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('Application not booted.');
        }
        return self::$instance;
    }

    public static function hasInstance(): bool
    {
        return self::$instance !== null;
    }

    public function db(): Database
    {
        return $this->db ??= Database::connect((array)$this->config->get('db'));
    }

    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->services[$id]);
    }

    public function forget(string $id): void
    {
        unset($this->services[$id]);
    }

    /**
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    public function make(string $id): object
    {
        if (!isset($this->services[$id])) {
            $this->services[$id] = isset($this->factories[$id]) ? ($this->factories[$id])($this) : new $id($this);
        }
        return $this->services[$id];
    }

    public function settings(): SettingsService
    {
        return $this->make(SettingsService::class);
    }

    public function crypto(): Crypto
    {
        return $this->make(Crypto::class);
    }

    public function logger(): Logger
    {
        return $this->make(Logger::class);
    }

    public function isDebug(): bool
    {
        return (bool)$this->config->get('app.debug', false);
    }

    public function timezone(): \DateTimeZone
    {
        static $tz = null;
        return $tz ??= new \DateTimeZone((string)$this->config->get('app.timezone', 'Asia/Kolkata'));
    }
}
