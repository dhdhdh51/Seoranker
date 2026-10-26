<?php
declare(strict_types=1);

/** Web installer. Locks itself after a successful install — delete this file afterwards. */
require dirname(__DIR__) . '/app/bootstrap.php';

use Flatmates\Services\Installer;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$storage = APP_ROOT . '/storage';
if (Installer::isLocked($storage)) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><title>Installed</title><p style="font-family:sans-serif;padding:2rem">Already installed. Delete <code>public/install.php</code>. <a href="./">Open the site</a></p>');
}
session_name('SFFINSTALL');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$errors = '';
$done = null;
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['_csrf'] ?? ''))) {
        $errors = 'Form expired, try again.';
    } else {
        $in = [
            'db_host' => trim((string)($_POST['db_host'] ?? 'localhost')), 'db_port' => (int)($_POST['db_port'] ?? 3306),
            'db_name' => trim((string)($_POST['db_name'] ?? '')), 'db_user' => trim((string)($_POST['db_user'] ?? '')), 'db_pass' => (string)($_POST['db_pass'] ?? ''),
            'app_url' => trim((string)($_POST['app_url'] ?? '')), 'admin_name' => trim((string)($_POST['admin_name'] ?? '')),
            'admin_email' => trim((string)($_POST['admin_email'] ?? '')), 'admin_password' => (string)($_POST['admin_password'] ?? ''),
            'timezone' => 'Asia/Kolkata', 'seed_locations' => true,
        ];
        try {
            $done = Installer::install($in, null, $storage);
        } catch (\Throwable $e) {
            $errors = $e->getMessage();
        }
    }
}
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$guessUrl = $scheme . '://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Student Flatmate Finder</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css"></head>
<body class="bg-body-tertiary"><main class="container py-5" style="max-width:720px">
<h1 class="h3 mb-4">Install Student Flatmate Finder</h1>
<?php if ($done): ?>
  <div class="alert alert-success">Installed! Configuration saved to <code><?= $h($done['config_path']) ?></code>.<br><strong>Now delete <code>public/install.php</code></strong>, add the cron jobs, then <a href="login">sign in</a> and configure SMTP in Admin → Settings.</div>
<?php else: ?>
  <table class="table table-sm small bg-body"><?php foreach (Installer::checks($storage) as $c): ?><tr><td><?= $c['ok'] ? '✅' : '❌' ?> <?= $h($c['label']) ?></td><td class="text-break"><?= $h($c['detail']) ?></td></tr><?php endforeach; ?></table>
  <?php if ($errors): ?><div class="alert alert-danger" style="white-space:pre-line"><?= $h($errors) ?></div><?php endif; ?>
  <form method="post" class="card card-body"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2 class="h6">Database (MySQL / MariaDB)</h2>
    <div class="row g-2 mb-3">
      <div class="col-8"><input class="form-control" name="db_host" value="<?= $h($in['db_host'] ?? 'localhost') ?>" placeholder="Host" aria-label="Host"></div>
      <div class="col-4"><input class="form-control" name="db_port" value="<?= $h($in['db_port'] ?? 3306) ?>" aria-label="Port"></div>
      <div class="col-md-4"><input class="form-control" name="db_name" value="<?= $h($in['db_name'] ?? '') ?>" placeholder="Database name" required aria-label="Database"></div>
      <div class="col-md-4"><input class="form-control" name="db_user" value="<?= $h($in['db_user'] ?? '') ?>" placeholder="User" required aria-label="User"></div>
      <div class="col-md-4"><input class="form-control" type="password" name="db_pass" placeholder="Password" aria-label="Password"></div>
    </div>
    <h2 class="h6">Site</h2>
    <input class="form-control mb-3" name="app_url" value="<?= $h($in['app_url'] ?? $guessUrl) ?>" required aria-label="Site URL">
    <h2 class="h6">Super Admin</h2>
    <div class="row g-2 mb-3">
      <div class="col-md-4"><input class="form-control" name="admin_name" value="<?= $h($in['admin_name'] ?? '') ?>" placeholder="Name" required aria-label="Name"></div>
      <div class="col-md-4"><input class="form-control" type="email" name="admin_email" value="<?= $h($in['admin_email'] ?? '') ?>" placeholder="Email" required aria-label="Email"></div>
      <div class="col-md-4"><input class="form-control" type="password" name="admin_password" placeholder="Password (8+ chars)" required minlength="8" autocomplete="new-password" aria-label="Admin password"></div>
    </div>
    <button class="btn btn-primary">Install</button>
  </form>
<?php endif; ?>
</main></body></html>
