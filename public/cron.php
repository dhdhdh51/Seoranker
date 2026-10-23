<?php
declare(strict_types=1);

/**
 * Web-trigger fallback for hosts WITHOUT PHP CLI cron.
 * Disabled by default. Enable in the private config file:
 *   'cron' => ['web_trigger_enabled' => true, 'web_trigger_secret' => '<64 hex chars>']
 * Call (preferably with the header):
 *   curl -fsS -H "X-Bharat-Cron-Key: <secret>" "https://seo.example.com/cron.php?task=process_queue"
 * Each task can run at most once per 55 seconds; failed key attempts are throttled per IP.
 */

define('BHARATSEO_WEB_CRON', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/cron/_cron.php';

use BharatSeo\Core\App;
use BharatSeo\Security\RateLimiter;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $app = App::boot();
} catch (\Throwable) {
    $respond(503, ['error' => 'unavailable']);
}

if (!$app->config->get('cron.web_trigger_enabled', false)) {
    $respond(404, ['error' => 'not found']);
}
$secret = (string)$app->config->get('cron.web_trigger_secret', '');
if (strlen($secret) < 32) {
    $respond(503, ['error' => 'web trigger secret is not configured (min 32 chars)']);
}
$ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
$limiter = $app->make(RateLimiter::class);
if ($limiter->tooMany('cronfail:' . $ip, 10, 3600)) {
    $respond(429, ['error' => 'too many failed attempts']);
}
$given = (string)($_SERVER['HTTP_X_BHARAT_CRON_KEY'] ?? ($_GET['key'] ?? ''));
if (!hash_equals($secret, $given)) {
    $limiter->hit('cronfail:' . $ip, 10, 3600);
    $respond(403, ['error' => 'forbidden']);
}
$task = (string)($_GET['task'] ?? 'process_queue');
if (!array_key_exists($task, BharatSeoCronTasks::registry())) {
    $respond(400, ['error' => 'unknown task']);
}
if (!$limiter->hit('cronweb:' . $task, 1, 55)) {
    $respond(429, ['error' => 'task ran less than 55 seconds ago']);
}
$result = bharatseo_run_cron($task, 'web', 25);
$respond($result['status'] === 'failed' ? 500 : 200, ['task' => $task] + $result);
