<?php
declare(strict_types=1);

namespace BharatSeo\Core;

use BharatSeo\Security\Crypto;
use BharatSeo\Services\SettingsService;

/**
 * Minimal service container / application context.
 */
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

    public static function boot(bool $requireConfig = true, ?array $configOverride = null): self
    {
        if ($configOverride !== null) {
            $config = new Config($configOverride, null);
        } else {
            $config = Config::load();
            if ($config === null) {
                if ($requireConfig) {
                    throw new NotInstalledException('Bharat SEO is not installed yet.');
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
        if ($this->db === null) {
            $this->db = Database::connect((array)$this->config->get('db'));
        }
        return $this->db;
    }

    public function setDb(Database $db): void
    {
        $this->db = $db;
        $this->services = [];
    }

    /** Register a factory override (used by tests to inject fakes). */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->services[$id]);
    }

    /**
     * Resolve a shared service by class name. Services take (App $app) in their constructor.
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    public function make(string $id): object
    {
        if (!isset($this->services[$id])) {
            if (isset($this->factories[$id])) {
                $this->services[$id] = ($this->factories[$id])($this);
            } else {
                $this->services[$id] = new $id($this);
            }
        }
        return $this->services[$id];
    }

    public function forget(string $id): void
    {
        unset($this->services[$id]);
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

    public function timezone(): \DateTimeZone
    {
        static $cache = [];
        $tz = (string)$this->config->get('app.timezone', 'Asia/Kolkata');
        try {
            if ($this->db !== null || $this->config->get('db.name')) {
                $tz = (string)$this->settings()->get('app.timezone', $tz);
            }
        } catch (\Throwable) {
            // settings table may not exist during install
        }
        return $cache[$tz] ??= new \DateTimeZone(in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Asia/Kolkata');
    }

    public function isDebug(): bool
    {
        return (bool)$this->config->get('app.debug', false);
    }
}
