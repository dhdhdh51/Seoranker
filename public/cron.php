<?php
declare(strict_types=1);

/**
 * Web-trigger fallback for hosts without CLI cron. Disabled by default; enable in the private
 * config: 'cron' => ['web_trigger_enabled' => true, 'web_trigger_secret' => '<64 hex>'].
 *   curl -fsS -H "X-Cron-Key: <secret>" "https://flatmates.example.com/cron.php?task=mail"
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Flatmates\Core\App;
use Flatmates\Security\RateLimiter;
use Flatmates\Services\CronTasks;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$out = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);
    exit;
};
try {
    $app = App::boot();
} catch (\Throwable) {
    $out(503, ['error' => 'unavailable']);
}
if (!$app->config->get('cron.web_trigger_enabled', false)) {
    $out(404, ['error' => 'not found']);
}
$secret = (string)$app->config->get('cron.web_trigger_secret', '');
if (strlen($secret) < 32) {
    $out(503, ['error' => 'secret not configured']);
}
$ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
$rl = $app->make(RateLimiter::class);
if ($rl->tooMany('cronfail:' . $ip, 10, 3600)) {
    $out(429, ['error' => 'too many attempts']);
}
if (!hash_equals($secret, (string)($_SERVER['HTTP_X_CRON_KEY'] ?? ''))) {
    $rl->hit('cronfail:' . $ip, 10, 3600);
    $out(403, ['error' => 'forbidden']);
}
$task = (string)($_GET['task'] ?? 'mail');
if (!isset(CronTasks::TASKS[$task])) {
    $out(400, ['error' => 'unknown task']);
}
if (!$rl->hit('cronweb:' . $task, 1, 50)) {
    $out(429, ['error' => 'ran recently']);
}
@set_time_limit(60);
$r = $app->make(CronTasks::class)->run($task);
$out($r['status'] === 'failed' ? 500 : 200, ['task' => $task] + $r);
