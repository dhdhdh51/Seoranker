<?php
use BharatSeo\Support\Labels;
$f = $filters;
?>
<div class="page-head">
  <div><h1 class="h3 mb-0">Backlink monitor</h1><div class="small text-body-secondary">Statuses reflect what our crawler observed in each referring page’s HTML. They are not search-engine index data.</div></div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="<?= e(url('/exports/backlinks', array_filter($f))) ?>"><i class="bi bi-download"></i> Export CSV</a>
    <?php if ($canManage): ?><form method="post" action="<?= e(url('/projects/' . $f['project_id'] . '/check-backlinks')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Run backlink check</button></form><?php endif; ?>
  </div>
</div>
<?php if (!$automationActive): ?><div class="alert alert-warning small">Cron is not running, so scheduled checks are <strong>not</strong> happening. Queued checks will wait until cron is configured.</div><?php endif; ?>

<form method="get" class="card card-soft mb-3"><div class="card-body row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label small mb-0">Project</label><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $f['project_id']]) ?></div>
  <div class="col-md-3"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><option value="">Any</option><?php foreach (Labels::BACKLINK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Lifecycle</label><select name="lifecycle" class="form-select form-select-sm"><option value="">Any</option><?php foreach (Labels::LIFECYCLE as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['lifecycle'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Search</label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
</div></form>

<div class="row g-3">
  <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>">
    <div class="card card-soft">
      <?php if (!$rows): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'link-45deg', 'heading' => 'No tracked backlinks', 'text' => $f['project_id'] ? 'Add referring pages where a link is expected, or use “Track backlink” on an approved prospect.' : 'Choose a project to add backlinks.']) ?><?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Referring page</th><th>Target</th><th>Anchor / rel</th><th>Status</th><th>Last verified</th></tr></thead>
        <tbody><?php foreach ($rows as $b): ?><tr>
          <td class="mw-0"><a href="<?= e(url('/backlinks/' . $b['id'])) ?>" class="d-block text-truncate fw-semibold"><?= e($b['referring_domain']) ?></a><span class="small text-body-secondary d-block text-truncate"><?= e($b['referring_page_url']) ?></span></td>
          <td class="small text-truncate mw-0"><?= e(str_limit($b['target_url'], 50)) ?></td>
          <td class="small"><?= e(str_limit($b['observed_anchor'] ?? '—', 40)) ?><?= $b['rel_attributes'] ? '<br><code>' . e($b['rel_attributes']) . '</code>' : '' ?></td>
          <td><?= badge($b['status']) ?><br><span class="small text-body-secondary"><?= e(Labels::LIFECYCLE[$b['lifecycle']] ?? '') ?></span><?= $b['pending_observation'] && $b['lifecycle'] === 'confirmed' ? '<br><span class="small text-warning-emphasis">confirming: ' . e($b['pending_observation']) . '</span>' : '' ?></td>
          <td class="small text-nowrap"><?= e(fmt_dt($b['last_verified_at'])) ?><br><span class="text-body-secondary">checked <?= e(fmt_dt($b['last_checked_at'])) ?></span></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
      <div class="card-footer bg-transparent"><?= \BharatSeo\Http\View::partial('partials/pagination', ['pager' => $pager]) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($canManage): ?>
  <div class="col-xl-4">
    <div class="card card-soft"><div class="card-body">
      <h2 class="h6">Add backlinks to monitor</h2>
      <form method="post" action="<?= e(url('/backlinks')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= (int)$f['project_id'] ?>">
        <div class="col-12"><label class="form-label small">Target URL on your site</label>
          <input name="target_url" class="form-control form-control-sm" list="target-list" required placeholder="https://…">
          <datalist id="target-list"><?php foreach ($targets as $t): ?><option value="<?= e($t['url']) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="col-12"><label class="form-label small">Referring page URLs (one per line)</label><textarea name="referring_urls" class="form-control form-control-sm font-monospace" rows="5" required></textarea></div>
        <div class="col-12"><label class="form-label small">Expected anchor (optional)</label><input name="expected_anchor" class="form-control form-control-sm" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Start monitoring</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
</div>
