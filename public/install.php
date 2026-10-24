<?php
declare(strict_types=1);

/**
 * Bharat SEO web installer. Locks itself after a successful installation
 * (storage/installed.lock + existing config) and can delete itself.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use BharatSeo\Http\Kernel;
use BharatSeo\Services\Installer;

$app = bharatseo_boot(false);
Kernel::securityHeaders(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
header('Cache-Control: no-store');
$storage = APP_ROOT . '/storage';

if (Installer::isLocked($storage)) {
    http_response_code(403);
    $locked = true;
} else {
    $locked = false;
    session_name('BSEOINSTALL');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
    if (is_dir($storage . '/sessions') && is_writable($storage . '/sessions')) {
        session_save_path($storage . '/sessions');
    }
    session_start();
    $_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
}

$errors = [];
$done = null;
$checks = Installer::environmentChecks($storage);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$basePath = rtrim(str_replace('/install.php', '', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$basePath = preg_replace('#/public$#', '', $basePath) ?? $basePath;
$defaults = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_socket' => '', 'app_url' => $scheme . '://' . $host . $basePath, 'timezone' => 'Asia/Kolkata', 'admin_name' => '', 'admin_email' => ''];
$in = $defaults;

if (!$locked && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($defaults as $k => $v) {
        $in[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if (!hash_equals((string)$_SESSION['install_csrf'], (string)($_POST['_csrf'] ?? ''))) {
        $errors[] = 'Form expired. Please try again.';
    } elseif ((string)($_POST['admin_password'] ?? '') !== (string)($_POST['admin_password_confirmation'] ?? '')) {
        $errors[] = 'The Super Admin passwords do not match.';
    } else {
        try {
            $done = Installer::install([
                'db_host' => $in['db_host'],
                'db_port' => (int)$in['db_port'],
                'db_name' => $in['db_name'],
                'db_user' => $in['db_user'],
                'db_pass' => (string)($_POST['db_pass'] ?? ''),
                'db_socket' => $in['db_socket'],
                'app_url' => rtrim($in['app_url'], '/'),
                'timezone' => $in['timezone'],
                'admin_name' => $in['admin_name'],
                'admin_email' => $in['admin_email'],
                'admin_password' => (string)($_POST['admin_password'] ?? ''),
                'pretty_urls' => !empty($_POST['pretty_urls']),
            ], null, $storage);
            $_SESSION = [];
            session_destroy();
            if (!empty($_POST['delete_installer'])) {
                $done['deleted'] = @unlink(__FILE__);
            }
        } catch (\InvalidArgumentException $e) {
            $errors = explode("\n", $e->getMessage());
        } catch (\Throwable $e) {
            \BharatSeo\Core\ErrorHandler::report($e);
            $errors[] = 'Installation failed: ' . \BharatSeo\Core\Logger::redactString($e->getMessage());
        }
    }
}
$cronDir = APP_ROOT . '/cron';
$php = Installer::cliBinaryGuess() ?? '/usr/local/bin/php';
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Install · NoidaRankers</title>
<link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
<main class="container py-5" style="max-width: 920px">
  <div class="d-flex align-items-center gap-2 mb-4"><span class="brand-mark">N</span><div><div class="fw-bold fs-4">NoidaRankers installer</div><div class="small text-body-secondary">Version <?= e(APP_VERSION) ?> · PHP <?= e(PHP_VERSION) ?></div></div></div>

<?php if ($locked): ?>
  <div class="card card-soft"><div class="card-body p-4">
    <h1 class="h4">Installer locked</h1>
    <p>NoidaRankers is already installed. For security the installer cannot be run again. Delete <code>public/install.php</code> from the server. To reinstall, follow UPGRADE.md (back up, then remove the config file and <code>storage/installed.lock</code>).</p>
    <a class="btn btn-primary" href="./">Go to sign in</a>
  </div></div>
<?php elseif ($done): ?>
  <div class="card card-soft"><div class="card-body p-4">
    <h1 class="h4 text-success">Installation complete</h1>
    <ul>
      <li>Configuration written to <code><?= e($done['config_path']) ?></code><?= str_starts_with($done['config_path'], APP_ROOT . '/config') ? ' (inside the app folder, protected by .htaccess — moving it to <code>../bharatseo-private/config.php</code> is recommended)' : ' (outside the application directory)' ?>.</li>
      <li>The installer is now locked<?= !empty($done['deleted']) ? ' and was deleted' : '. <strong>Delete public/install.php</strong> from the server' ?>.</li>
      <li>No automation runs until you add the cron jobs below (Cron &amp; queue health will warn you).</li>
    </ul>
<pre class="cron-pre">*/5 * * * * <?= e($php) ?> <?= e($cronDir) ?>/process_queue.php >/dev/null 2>&1
*/10 * * * * <?= e($php) ?> <?= e($cronDir) ?>/send_approved_outreach.php >/dev/null 2>&1
15 * * * * <?= e($php) ?> <?= e($cronDir) ?>/check_backlinks.php >/dev/null 2>&1
30 */6 * * * <?= e($php) ?> <?= e($cronDir) ?>/discover.php >/dev/null 2>&1
45 3 * * * <?= e($php) ?> <?= e($cronDir) ?>/cleanup.php >/dev/null 2>&1</pre>
    <a class="btn btn-primary" href="./login">Sign in</a>
  </div></div>
<?php else: ?>
  <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="card card-soft mb-4"><div class="card-header bg-transparent fw-semibold">1 · Environment</div>
    <ul class="list-group list-group-flush small">
      <?php foreach ($checks as $c): ?><li class="list-group-item d-flex justify-content-between gap-2"><span><?= e($c['label']) ?><div class="text-body-secondary text-break"><?= e($c['detail']) ?></div></span><span class="badge <?= $c['ok'] ? 'text-bg-success' : ($c['required'] ? 'text-bg-danger' : 'text-bg-warning') ?> align-self-center"><?= $c['ok'] ? 'OK' : ($c['required'] ? 'Required' : 'Warning') ?></span></li><?php endforeach; ?>
    </ul>
  </div>
  <form method="post" class="card card-soft" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($_SESSION['install_csrf']) ?>">
    <div class="card-header bg-transparent fw-semibold">2 · Database (create an empty MySQL 8 / MariaDB database and user in cPanel first)</div>
    <div class="card-body row g-3">
      <div class="col-md-6"><label class="form-label">Host</label><input class="form-control" name="db_host" value="<?= e($in['db_host']) ?>" required></div>
      <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="db_port" value="<?= e($in['db_port']) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Socket (optional)</label><input class="form-control" name="db_socket" value="<?= e($in['db_socket']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Database name</label><input class="form-control" name="db_name" value="<?= e($in['db_name']) ?>" required></div>
      <div class="col-md-4"><label class="form-label">User</label><input class="form-control" name="db_user" value="<?= e($in['db_user']) ?>" required></div>
      <div class="col-md-4"><label class="form-label">Password</label><input class="form-control" type="password" name="db_pass" autocomplete="new-password"></div>
    </div>
    <div class="card-header bg-transparent fw-semibold border-top">3 · Application</div>
    <div class="card-body row g-3">
      <div class="col-md-8"><label class="form-label">Application URL</label><input class="form-control" name="app_url" value="<?= e($in['app_url']) ?>" required><div class="form-text">Use https:// in production.</div></div>
      <div class="col-md-4"><label class="form-label">Timezone</label><select class="form-select" name="timezone"><?php foreach (\DateTimeZone::listIdentifiers() as $tz): ?><option<?= $tz === $in['timezone'] ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="pretty_urls" value="1" id="pu" checked><label class="form-check-label" for="pu">Clean URLs (requires mod_rewrite / .htaccess; untick to use /index.php/... URLs)</label></div></div>
    </div>
    <div class="card-header bg-transparent fw-semibold border-top">4 · First Super Admin (no default accounts exist)</div>
    <div class="card-body row g-3">
      <div class="col-md-6"><label class="form-label">Name</label><input class="form-control" name="admin_name" value="<?= e($in['admin_name']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="admin_email" value="<?= e($in['admin_email']) ?>" required></div>
      <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" type="password" name="admin_password" required minlength="10" autocomplete="new-password"><div class="form-text">At least 10 characters, letters plus a number or symbol.</div></div>
      <div class="col-md-6"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="admin_password_confirmation" required autocomplete="new-password"></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="delete_installer" value="1" id="di" checked><label class="form-check-label" for="di">Delete this installer file after a successful installation</label></div></div>
      <div class="col-12"><button class="btn btn-primary btn-lg">NoidaRankers/button></div>
    </div>
  </form>
<?php endif; ?>
</main>
</body>
</html>
