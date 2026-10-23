<?php
/**
 * Bharat SEO configuration template.
 *
 * install.php generates the real file automatically. If you create it by hand:
 *   1. Copy this file OUTSIDE the web root, preferably to
 *        <one level above the app folder>/bharatseo-private/config.php
 *      (or set the BHARATSEO_CONFIG environment variable to its path).
 *      config/config.php is the fallback location and is protected by .htaccess.
 *   2. Generate a key:  php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
 *   3. chmod 640 the file. Never commit it, never place it under public/.
 *
 * There are NO default credentials. Every value below must be set by you.
 */
return [
    'app' => [
        'name' => 'Bharat SEO',
        'url' => 'https://seo.example.com',      // public base URL, no trailing slash
        'env' => 'production',                    // production | development
        'debug' => false,                         // never true in production
        'timezone' => 'Asia/Kolkata',
        'key' => '',                              // REQUIRED: base64:... (32 random bytes)
        'pretty_urls' => true,                    // false if mod_rewrite is unavailable (uses /index.php/...)
        'demo_mode' => false,                     // allows bin/seed_demo.php in development only
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
        // Keep storage outside the public web root. Must be writable by PHP.
        'storage' => __DIR__ . '/../storage',
    ],
    'session' => [
        'name' => 'BSEOSESSID',
        'idle_minutes' => 60,
        'absolute_hours' => 12,
        'secure_cookie' => 'auto',                // auto | true | false
    ],
    'cron' => [
        // Web-trigger fallback for hosts WITHOUT CLI cron (see CRON_SETUP.md).
        'web_trigger_enabled' => false,
        'web_trigger_secret' => '',               // php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    ],
    'security' => [
        // IPs of reverse proxies whose X-Forwarded-For header may be trusted (e.g. Cloudflare via your host)
        'trusted_proxies' => [],
    ],
];
