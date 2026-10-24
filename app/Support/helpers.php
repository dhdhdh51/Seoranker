<?php
declare(strict_types=1);

use BharatSeo\Core\App;
use BharatSeo\Security\Csrf;
use BharatSeo\Support\Labels;

function app(): App
{
    return App::get();
}

/** HTML-escape for text and attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Base path of the application (e.g. "" or "/seo"). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $cfg = App::hasInstance() ? (string)app()->config->get('app.url', '') : '';
    if ($cfg !== '') {
        $base = rtrim((string)(parse_url($cfg, PHP_URL_PATH) ?? ''), '/');
    } else {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(str_replace('/index.php', '', preg_replace('#/(install|cron)\.php$#', '', $script) ?? ''), '/');
    }
    return $base;
}

/** Build an application URL for a route path like "/projects/5". */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $pretty = !App::hasInstance() || (bool)app()->config->get('app.pretty_urls', true);
    $u = base_path() . ($pretty ? '' : '/index.php') . ($path === '/' && $pretty ? '/' : $path);
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    return $u . ($query ? '?' . http_build_query($query) : '');
}

function absolute_url(string $path = '/', array $query = []): string
{
    $cfg = App::hasInstance() ? rtrim((string)app()->config->get('app.url', ''), '/') : '';
    if ($cfg !== '') {
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $cfg) ?? $cfg;
    } else {
        $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $origin . url($path, $query);
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : APP_VERSION;
    return base_path() . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

/** Format a UTC datetime string in the configured timezone. */
function fmt_dt(?string $utc, string $format = 'd M Y, H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(app()->timezone())->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function fmt_date(?string $utc): string
{
    return fmt_dt($utc, 'd M Y');
}

/** Convert a local (configured timezone) date "Y-m-d" to a UTC datetime boundary. */
function local_date_to_utc(string $date, bool $endOfDay = false): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($date . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), app()->timezone());
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function local_now(string $format = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('now', app()->timezone()))->format($format);
}

function str_limit(?string $s, int $max = 80): string
{
    $s = (string)$s;
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

function badge(string $status, ?string $label = null): string
{
    [$cls, $text] = Labels::badge($status);
    return '<span class="badge rounded-pill ' . e($cls) . '">' . e($label ?? $text) . '</span>';
}

function num(int|float|null $n): string
{
    return number_format((float)($n ?? 0));
}

function selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(mixed $v): string
{
    return $v ? ' checked' : '';
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function json_script(string $id, mixed $data): string
{
    return '<script type="application/json" id="' . e($id) . '">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>';
}

/** Safe external link (only http/https). */
function ext_link(?string $href, ?string $text = null, int $limit = 60): string
{
    $href = (string)$href;
    if (!preg_match('#^https?://#i', $href)) {
        return e($text ?? $href);
    }
    return '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer nofollow">' . e(str_limit($text ?? $href, $limit)) . ' <i class="bi bi-box-arrow-up-right small"></i></a>';
}

/** Product / agency name shown in the UI and emails (Settings → General → Agency name). */
function brand(): string
{
    static $name = null;
    if ($name !== null) {
        return $name;
    }
    $name = 'NoidaRankers';
    try {
        if (App::hasInstance()) {
            $name = (string)(app()->settings()->get('app.agency_name') ?: app()->config->get('app.name', $name));
        }
    } catch (Throwable) {
        // settings table unavailable (installer)
    }
    return $name;
}

function brand_initial(): string
{
    return mb_strtoupper(mb_substr(brand(), 0, 1));
}
