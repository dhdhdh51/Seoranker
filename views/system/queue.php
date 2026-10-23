<?php $cronDir = $appRoot . '/cron'; ?>
<div class="page-head">
  <div><h1 class="h3 mb-0">Cron &amp; queue health</h1><div class="small text-body-secondary">MySQL-backed queue with locking, retries, exponential backoff and a failed-job archive.</div></div>
  <div class="d-flex gap-2">
    <?php if ($paused): ?>
      <form method="post" action="<?= e(url('/system/queue/resume')) ?>"><?= csrf_field() ?><button class="btn btn-success"><i class="bi bi-play-fill"></i> Resume all jobs</button></form>
    <?php else: ?>
      <form method="post" action="<?= e(url('/system/queue/pause')) ?>" data-confirm="Pause ALL queued jobs (discovery, evaluation, monitoring and sending) immediately?"><?= csrf_field() ?><button class="btn btn-danger"><i class="bi bi-pause-fill"></i> Pause all jobs</button></form>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/system/queue/run')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary" title="Processes up to 10 jobs within ~20 s"><i class="bi bi-lightning"></i> Process a small batch now</button></form>
  </div>
</div>
<?php foreach ($warnings as $w): ?><div class="alert alert-<?= e($w['level']) ?> py-2 small"><?= e($w['text']) ?></div><?php endforeach; ?>

<div class="row g-3 mb-3">
  <?php foreach (['pending' => 'Pending', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed', 'unresolved_failed' => 'Failed — needs review'] as $k => $l): ?>
    <div class="col-6 col-md"><div class="card kpi-mini"><div class="card-body"><div class="kpi-label"><?= e($l) ?></div><div class="kpi-value"><?= num((int)$stats[$k]) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-xl-7">
    <div class="card card-soft h-100"><div class="card-header bg-transparent fw-semibold">Cron scripts</div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Script</th><th>Health</th><th>Last successful run</th><th>Last status</th><th>Expected</th></tr></thead>
        <tbody><?php foreach ($health as $script => $h): ?><tr>
          <td class="small"><code><?= e($script) ?>.php</code><div class="text-body-secondary"><?= e($h['label']) ?></div></td>
          <td><?= $h['healthy'] ? badge('ok', 'Running') : badge('failed', $h['last_finished'] ? 'Stale' : 'Never ran') ?></td>
          <td class="small"><?= e(fmt_dt($h['last_finished'])) ?><?= $h['last_trigger'] ? ' · ' . e($h['last_trigger']) : '' ?></td>
          <td class="small"><?= $h['last_status'] ? badge($h['last_status']) : '—' ?><?= $h['last_message'] ? '<div class="text-body-secondary text-break">' . e(str_limit($h['last_message'], 90)) . '</div>' : '' ?></td>
          <td class="small">every ≤ <?= (int)$h['expected_minutes'] ?> min</td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card card-soft h-100"><div class="card-header bg-transparent fw-semibold">cPanel / aPanel cron commands</div><div class="card-body small">
      <p>Add these in <em>Cron Jobs</em> (replace the PHP path if your host uses a different one, e.g. <code>/usr/local/bin/php</code> or <code>/opt/cpanel/ea-php82/root/usr/bin/php</code>):</p>
<pre class="cron-pre">*/5 * * * * <?= e($phpBinary) ?> <?= e($cronDir) ?>/process_queue.php >/dev/null 2>&1
*/10 * * * * <?= e($phpBinary) ?> <?= e($cronDir) ?>/send_approved_outreach.php >/dev/null 2>&1
15 */1 * * * <?= e($phpBinary) ?> <?= e($cronDir) ?>/check_backlinks.php >/dev/null 2>&1
30 */6 * * * <?= e($phpBinary) ?> <?= e($cronDir) ?>/discover.php >/dev/null 2>&1
45 3 * * * <?= e($phpBinary) ?> <?= e($cronDir) ?>/cleanup.php >/dev/null 2>&1</pre>
      <p class="mb-1">Web-trigger fallback (only if your host has no CLI cron): <?= $webTrigger ? badge('ok', 'enabled') : badge('none', 'disabled') ?>. See CRON_SETUP.md — the secret lives in the private config file.</p>
    </div></div>
  </div>
</div>

<div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Failed jobs needing review</div>
  <?php if (!$failed): ?><div class="card-body small text-body-secondary">No unresolved failed jobs.</div><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>#</th><th>Type</th><th>Project</th><th>Error</th><th>Attempts</th><th>Failed</th><th class="text-end">Action</th></tr></thead>
    <tbody><?php foreach ($failed as $f): ?><tr>
      <td class="small"><?= (int)$f['id'] ?></td><td class="small"><code><?= e($f['type']) ?></code></td><td class="small"><?= e($f['project_name'] ?? '—') ?></td>
      <td class="small text-break"><?= e(str_limit($f['error'], 160)) ?><details><summary class="small">payload</summary><code class="small text-break"><?= e($f['payload']) ?></code></details></td>
      <td class="small"><?= (int)$f['attempts'] ?></td><td class="small text-nowrap"><?= e(fmt_dt($f['failed_at'])) ?></td>
      <td class="text-end text-nowrap">
        <form method="post" action="<?= e(url('/system/failed/' . $f['id'] . '/retry')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Retry</button></form>
        <form method="post" action="<?= e(url('/system/failed/' . $f['id'] . '/dismiss')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Dismiss</button></form>
      </td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-xl-5">
    <div class="card card-soft h-100"><div class="card-header bg-transparent fw-semibold">Jobs by type</div>
      <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Type</th><th class="text-end">Pending</th><th class="text-end">Running</th><th class="text-end">Failed</th><th class="text-end">Done</th></tr></thead>
      <tbody><?php foreach ($byType as $t): ?><tr><td class="small"><code><?= e($t['type']) ?></code></td><td class="text-end small"><?= num((int)$t['pending']) ?></td><td class="text-end small"><?= num((int)$t['running']) ?></td><td class="text-end small"><?= num((int)$t['failed']) ?></td><td class="text-end small"><?= num((int)$t['completed']) ?></td></tr><?php endforeach; ?>
      <?php if (!$byType): ?><tr><td colspan="5" class="small text-body-secondary">Queue is empty.</td></tr><?php endif; ?></tbody></table></div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card card-soft h-100"><div class="card-header bg-transparent d-flex justify-content-between"><span class="fw-semibold">Latest jobs</span>
      <form method="get"><select name="status" class="form-select form-select-sm" data-autosubmit><option value="">All</option><?php foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= selected($statusFilter, $s) ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></form></div>
      <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Type</th><th>Status</th><th>Attempts</th><th>Available</th><th>Last error</th></tr></thead>
      <tbody><?php foreach ($jobs as $j): ?><tr><td class="small"><?= (int)$j['id'] ?></td><td class="small"><code><?= e($j['type']) ?></code></td><td><?= badge($j['status']) ?></td><td class="small"><?= (int)$j['attempts'] ?>/<?= (int)$j['max_attempts'] ?></td><td class="small text-nowrap"><?= e(fmt_dt($j['available_at'])) ?></td><td class="small text-break"><?= e(str_limit($j['last_error'], 80)) ?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
  </div>
</div>
<div class="card card-soft mt-3"><div class="card-header bg-transparent fw-semibold">Recent cron runs</div>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Script</th><th>Trigger</th><th>Status</th><th class="text-end">Processed</th><th>Started</th><th>Finished</th><th>Message</th></tr></thead>
  <tbody><?php foreach ($recentRuns as $r): ?><tr><td class="small"><?= e($r['script']) ?></td><td class="small"><?= e($r['trigger_type']) ?></td><td><?= badge($r['status']) ?></td><td class="text-end small"><?= num((int)$r['processed']) ?></td><td class="small text-nowrap"><?= e(fmt_dt($r['started_at'], 'd M H:i:s')) ?></td><td class="small text-nowrap"><?= e(fmt_dt($r['finished_at'], 'd M H:i:s')) ?></td><td class="small text-break"><?= e(str_limit($r['message'], 80)) ?></td></tr><?php endforeach; ?>
  <?php if (!$recentRuns): ?><tr><td colspan="7" class="small text-danger">No cron run has ever been recorded. Automation is not active.</td></tr><?php endif; ?></tbody></table></div>
</div>
