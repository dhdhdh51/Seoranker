<h1 class="h4 mb-3">System health</h1>
<div class="table-responsive"><table class="table small bg-body"><tbody>
<?php foreach ($checks as [$name, $detail, $ok]): ?><tr><td><?= $ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-exclamation-triangle-fill text-warning"></i>' ?> <?= e($name) ?></td><td class="text-break"><?= e($detail) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="card border-0 shadow-sm"><div class="card-body small">
  <h2 class="h6">Cron jobs</h2>
  <pre class="bg-body-tertiary p-2 rounded mb-2">*/2 * * * * <?= e(PHP_SAPI === 'cli' ? 'php' : 'php') ?> <?= e($root) ?>/cron/mail.php
15 3 * * *  php <?= e($root) ?>/cron/cleanup.php
30 3 * * *  php <?= e($root) ?>/cron/deletions.php</pre>
  <p class="mb-0">No CLI cron? Enable the web trigger in the private config (<code>cron.web_trigger_enabled</code>) and call <code>/cron.php?task=mail</code> with header <code>X-Cron-Key</code>. Web trigger: <strong><?= $webCron ? 'enabled' : 'disabled' ?></strong>.</p>
</div></div>
