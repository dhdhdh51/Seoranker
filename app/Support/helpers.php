<?php
declare(strict_types=1);

use Flatmates\Core\App;
use Flatmates\Security\Csrf;

function app(): App
{
    return App::get();
}

function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function base_path(): string
{
    static $b = null;
    if ($b === null) {
        $cfg = App::hasInstance() ? (string)app()->config->get('app.url', '') : '';
        $b = $cfg !== '' ? rtrim((string)(parse_url($cfg, PHP_URL_PATH) ?? ''), '/') : '';
    }
    return $b;
}

function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    return base_path() . $path . ($query ? '?' . http_build_query($query) : '');
}

function absolute_url(string $path = '/', array $query = []): string
{
    $cfg = App::hasInstance() ? rtrim((string)app()->config->get('app.url', ''), '/') : '';
    $origin = $cfg !== '' ? (preg_replace('#^(https?://[^/]+).*$#', '$1', $cfg) ?? $cfg) : 'http://localhost';
    return $origin . url($path, $query);
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    return base_path() . '/assets/' . ltrim($path, '/') . '?v=' . (is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : APP_VERSION);
}

function media_url(?string $path): ?string
{
    return $path ? base_path() . '/media/' . ltrim($path, '/') : null;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function setting(string $key, mixed $default = null): mixed
{
    try {
        return app()->settings()->get($key, $default);
    } catch (Throwable) {
        return $default;
    }
}

function brand(): string
{
    return (string)(setting('brand.name') ?: 'Student Flatmate Finder');
}

function fmt_dt(?string $utc, string $format = 'd M Y, H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(app()->timezone())->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function fmt_date(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $t = strtotime($d);
    return $t ? date('d M Y', $t) : '—';
}

function ago(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    $s = time() - (int)strtotime($utc . ' UTC');
    return match (true) {
        $s < 60 => 'just now',
        $s < 3600 => intdiv($s, 60) . ' min ago',
        $s < 86400 => intdiv($s, 3600) . ' h ago',
        $s < 86400 * 30 => intdiv($s, 86400) . ' d ago',
        default => fmt_dt($utc, 'd M Y'),
    };
}

function inr(int|float|null $n): string
{
    if ($n === null) {
        return '—';
    }
    $n = (int)$n;
    $s = (string)abs($n);
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s = $rest . ',' . $last3;
    }
    return '₹' . ($n < 0 ? '-' : '') . $s;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(mixed $v): string
{
    return $v ? ' checked' : '';
}

function str_limit(?string $s, int $max = 80): string
{
    $s = (string)$s;
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

function json_script(string $id, mixed $data): string
{
    return '<script type="application/json" id="' . e($id) . '">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>';
}

function label(string $group, ?string $value): string
{
    return \Flatmates\Support\Options::label($group, $value);
}

function initials(string $name): string
{
    preg_match_all('/(?<!\S)\p{L}/u', $name, $m);
    $letters = $m[0] ?? [];
    return $letters ? mb_strtoupper($letters[0] . ($letters[1] ?? '')) : '?';
}

function invalid(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}

function avatar(?string $photo, string $name, string $size = 'md'): string
{
    if ($photo) {
        return '<img class="avatar avatar-' . e($size) . '" src="' . e(media_url($photo)) . '" alt="" loading="lazy">';
    }
    return '<span class="avatar avatar-' . e($size) . ' avatar-initials" aria-hidden="true">' . e(initials($name)) . '</span>';
}
