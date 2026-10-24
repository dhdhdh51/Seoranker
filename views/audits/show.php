<?php
$running = in_array($a['status'], ['queued', 'running'], true);
$info = $checks['info'] ?? [];
$sevBadge = ['error' => 'critical', 'warning' => 'warning', 'notice' => 'info'];
$score = $a['score'];
$band = $score === null ? 'low' : ($score >= 80 ? 'high' : ($score >= 60 ? 'review' : 'low'));
$yes = static fn($v) => $v ? badge('ok', 'Yes') : badge('failed', 'No');
?>
<div class="page-head">
  <div class="min-w-0">
    <div class="small"><a href="<?= e(url('/audits', ['project_id' => $a['project_id']])) ?>">← Site audits</a> · <a href="<?= e(url('/projects/' . $a['project_id'])) ?>"><?= e($a['project_name']) ?></a></div>
    <h1 class="h4 mb-0 text-break">Site audit · <?= e($a['host']) ?> <?= badge($a['status']) ?></h1>
    <div class="small text-body-secondary">Started <?= e(fmt_dt($a['started_at'] ?? $a['created_at'])) ?><?= $a['finished_at'] ? ' · finished ' . e(fmt_dt($a['finished_at'])) : '' ?> · up to <?= (int)$a['max_pages'] ?> pages, depth <?= (int)$a['max_depth'] ?></div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($running && $canManage): ?>
      <form method="post" action="<?= e(url('/audits/' . $a['id'] . '/continue')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-play-fill"></i> Crawl next batch now</button></form>
      <form method="post" action="<?= e(url('/audits/' . $a['id'] . '/cancel')) ?>" data-confirm="Cancel this audit?"><?= csrf_field() ?><button class="btn btn-outline-danger">Cancel</button></form>
    <?php endif; ?>
    <?php if ($a['status'] === 'completed'): ?>
      <a class="btn btn-outline-secondary" href="<?= e(url('/audits/' . $a['id'] . '/export')) ?>"><i class="bi bi-download"></i> Export CSV</a>
      <button class="btn btn-outline-secondary" type="button" data-print><i class="bi bi-printer"></i> Print / PDF</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($running): ?>
  <div class="alert alert-info small" data-autorefresh="15"><i class="bi bi-hourglass-split"></i> Crawling… <?= num((int)$a['pages_crawled']) ?> page(s) done, <?= num((int)$a['pages_found']) ?> found. This page refreshes automatically; the queue cron keeps going in the background, or click “Crawl next batch now”.</div>
<?php endif; ?>
<?php if ($a['status'] === 'failed'): ?><div class="alert alert-danger small"><?= e($a['error_message']) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card kpi-mini h-100"><div class="card-body text-center"><div class="kpi-label">Health score</div><div class="score-big score-<?= e($band) ?> mt-1"><?= $score !== null ? (int)$score : '—' ?><small>/100</small></div></div></div></div>
  <?php foreach ([['Pages audited', (int)$a['pages_crawled']], ['Errors', (int)$a['error_count']], ['Warnings', (int)$a['warning_count']], ['Notices', (int)$a['notice_count']]] as [$l, $n]): ?>
    <div class="col-6 col-md"><div class="card kpi-mini h-100"><div class="card-body"><div class="kpi-label"><?= e($l) ?></div><div class="kpi-value"><?= num($n) ?></div></div></div></div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Issues (<?= count($groups) ?> types)</div>
      <?php if (!$groups): ?><div class="card-body small text-body-secondary"><?= $running ? 'Issues appear when the crawl finishes.' : 'No issues found — excellent.' ?></div><?php endif; ?>
      <div class="accordion accordion-flush" id="issues">
        <?php foreach ($groups as $i => $g): ?>
          <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#iss<?= $i ?>">
              <span class="me-2"><?= badge($sevBadge[$g['severity']], ucfirst($g['severity'])) ?></span>
              <span class="flex-grow-1"><?= e($g['title']) ?></span>
              <span class="badge text-bg-light border ms-2"><?= $g['site'] ? 'site-wide' : count($g['pages']) . ' page(s)' ?></span>
            </button></h2>
            <div id="iss<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#issues"><div class="accordion-body small">
              <p class="mb-2"><strong>How to fix:</strong> <?= e($g['fix']) ?></p>
              <?php if ($g['pages']): ?><ul class="mb-0"><?php foreach (array_slice($g['pages'], 0, 100) as $p): ?><li class="text-break"><?= ext_link($p['url'], null, 100) ?><?= $p['http_status'] ? ' · HTTP ' . (int)$p['http_status'] : '' ?><?= $g['code'] === 'title_too_long' || $g['code'] === 'title_too_short' || $g['code'] === 'duplicate_title' ? ' · “' . e(str_limit($p['title'], 80)) . '”' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
            </div></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Pages (<?= count($pages) ?>)</div>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>URL</th><th>HTTP</th><th>Title</th><th class="text-end">Words</th><th class="text-end">ms</th><th class="text-end">Issues</th></tr></thead>
        <tbody><?php foreach ($pages as $p): $iss = json_decode((string)$p['issues'], true) ?: []; $errs = count(array_filter($iss, static fn($c) => ($catalog[$c][0] ?? '') === 'error')); ?>
          <tr><td class="small mw-0"><span class="d-block text-truncate" title="<?= e($p['url']) ?>"><?= e(preg_replace('#^https?://[^/]+#', '', $p['url']) ?: '/') ?></span></td>
          <td class="small"><?= $p['http_status'] ? (int)$p['http_status'] : '<span class="text-danger">—</span>' ?></td>
          <td class="small text-truncate" style="max-width:240px" title="<?= e($p['title']) ?>"><?= e(str_limit($p['title'] ?? ($p['error'] ?? ''), 60)) ?></td>
          <td class="small text-end"><?= $p['word_count'] !== null ? num((int)$p['word_count']) : '—' ?></td>
          <td class="small text-end"><?= $p['response_ms'] ? num((int)$p['response_ms']) : '—' ?></td>
          <td class="small text-end"><?= $errs ? '<span class="badge text-bg-danger">' . $errs . '</span> ' : '' ?><?= count($iss) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$pages): ?><tr><td colspan="6" class="small text-body-secondary">No pages crawled yet.</td></tr><?php endif; ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Site-wide checks</div>
      <ul class="list-group list-group-flush small">
        <li class="list-group-item d-flex justify-content-between"><span>HTTPS works</span><?= $yes(!empty($info['https_ok'])) ?></li>
        <li class="list-group-item d-flex justify-content-between"><span>http:// redirects to https://</span><?= $yes(str_starts_with((string)($info['http_redirects_to'] ?? ''), 'https://')) ?></li>
        <li class="list-group-item d-flex justify-content-between"><span>robots.txt</span><?= $yes(($info['robots_status'] ?? 0) == 200) ?></li>
        <li class="list-group-item d-flex justify-content-between"><span>XML sitemap</span><span><?= !empty($info['sitemap_found']) ? badge('ok', num((int)$info['sitemap_url_count']) . ' URLs') : badge('failed', 'Not found') ?></span></li>
        <li class="list-group-item d-flex justify-content-between"><span>Homepage response</span><span><?= isset($info['homepage_ms']) ? num((int)$info['homepage_ms']) . ' ms' : '—' ?></span></li>
        <li class="list-group-item d-flex justify-content-between"><span><?= e($info['alternate_host'] ?? 'www') ?> consolidated</span><?= $yes(!in_array('host_not_consolidated', $checks['issues'] ?? [], true)) ?></li>
      </ul>
    </div>
    <div class="card card-soft"><div class="card-body small">
      <h2 class="h6">How the score works</h2>
      <p class="mb-1">Start at 100. For every issue type subtract its weight (error 10, warning 4, notice 1) scaled by the share of pages affected (minimum 25 %); site-wide issues count in full.</p>
      <p class="mb-0 text-body-secondary">This audit checks the HTML our crawler fetches. It does not run JavaScript, and it is not a Google ranking prediction. For Core Web Vitals use Google PageSpeed Insights / Search Console.</p>
    </div></div>
  </div>
</div>
