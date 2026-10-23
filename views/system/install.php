<div class="page-head"><div><h1 class="h3 mb-0">Installation &amp; upgrade</h1><div class="small text-body-secondary">Bharat SEO v<?= e($version) ?> · Database <?= e($dbVersion) ?> · PHP <?= e(PHP_VERSION) ?></div></div></div>
<div class="row g-3">
  <div class="col-xl-6">
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Security posture</div><ul class="list-group list-group-flush small">
      <li class="list-group-item d-flex justify-content-between"><span>Config file</span><span class="text-break text-end"><?= e($configPath ?? 'unknown') ?></span></li>
      <li class="list-group-item d-flex justify-content-between"><span>Config outside public web root</span><?= $configOutsideWeb ? badge('ok', 'Yes') : badge('failed', 'No') ?></li>
      <li class="list-group-item d-flex justify-content-between"><span>Installer locked (storage/installed.lock)</span><?= $lockPresent ? badge('ok', 'Locked') : badge('failed', 'Not locked') ?></li>
      <li class="list-group-item d-flex justify-content-between"><span>public/install.php present</span><?= $installerPresent ? badge('warning', 'Present (locked) — you may delete it') : badge('ok', 'Removed') ?></li>
      <li class="list-group-item d-flex justify-content-between"><span>Debug mode</span><?= app()->isDebug() ? badge('failed', 'ON — disable in production') : badge('ok', 'Off') ?></li>
      <li class="list-group-item d-flex justify-content-between"><span>Environment</span><span><?= e((string)app()->config->get('app.env')) ?></span></li>
    </ul></div>
    <div class="card card-soft"><div class="card-header bg-transparent d-flex justify-content-between"><span class="fw-semibold">Database migrations</span>
      <form method="post" action="<?= e(url('/system/migrate')) ?>" data-confirm="Back up the database first (see UPGRADE.md). Apply pending migrations now?"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Apply pending</button></form></div>
      <ul class="list-group list-group-flush small"><?php foreach ($migrations as $v => $m): ?><li class="list-group-item d-flex justify-content-between"><span><code><?= e($v) ?></code> <span class="text-body-secondary"><?= e($m['file']) ?></span></span><?= $m['applied_at'] ? badge('ok', 'applied ' . fmt_date($m['applied_at'])) : badge('pending', 'pending') ?></li><?php endforeach; ?></ul>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Environment checks</div><ul class="list-group list-group-flush small">
      <?php foreach ($checks as $c): ?><li class="list-group-item d-flex justify-content-between gap-2"><span><?= e($c['label']) ?><div class="text-body-secondary text-break"><?= e($c['detail']) ?></div></span><?= $c['ok'] ? badge('ok', 'OK') : badge($c['required'] ? 'failed' : 'warning', $c['required'] ? 'Fail' : 'Warning') ?></li><?php endforeach; ?>
    </ul></div>
  </div>
</div>
