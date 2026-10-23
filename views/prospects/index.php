<?php
use BharatSeo\Support\Labels;
$f = $filters;
$isStaff = $currentUser->isStaff();
$exportQuery = array_filter($f, static fn($v) => $v !== '' && $v !== null);
?>
<div class="page-head">
  <h1 class="h3 mb-0">Prospects</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= e(url('/exports/prospects', $exportQuery)) ?>"><i class="bi bi-download"></i> Export CSV</a>
    <?php if ($isStaff): ?><a class="btn btn-primary" href="<?= e(url('/discovery', ['project_id' => $f['project_id']])) ?>"><i class="bi bi-plus-lg"></i> Add prospects</a><?php endif; ?>
  </div>
</div>

<form method="get" class="card card-soft mb-3"><div class="card-body row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label small mb-0">Project</label><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $f['project_id']]) ?></div>
  <div class="col-md-2"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><option value="">Any</option><?php foreach (Labels::PROSPECT_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['status'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Category</label><select name="category" class="form-select form-select-sm"><option value="">Any</option><?php foreach (Labels::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['category'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Score band</label><select name="band" class="form-select form-select-sm"><option value="">Any</option><option value="high"<?= selected($f['band'], 'high') ?>>80–100 high priority</option><option value="review"<?= selected($f['band'], 'review') ?>>60–79 review</option><option value="low"<?= selected($f['band'], 'low') ?>>&lt;60 low</option></select></div>
  <div class="col-md-1"><label class="form-label small mb-0">Risk</label><select name="risk" class="form-select form-select-sm"><option value="">Any</option><option value="critical"<?= selected($f['risk'], 'critical') ?>>Critical</option><option value="hold"<?= selected($f['risk'], 'hold') ?>>On hold</option></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Source</label><select name="source" class="form-select form-select-sm"><option value="">Any</option><?php foreach (Labels::SOURCES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['source'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0">Search</label><input name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm" placeholder="URL, title or domain"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Discovered from</label><input type="date" name="from" value="<?= e($f['from']) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><label class="form-label small mb-0">to</label><input type="date" name="to" value="<?= e($f['to']) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><label class="form-label small mb-0">Sort</label><select name="sort" class="form-select form-select-sm"><option value="">Score (high first)</option><option value="score_asc"<?= selected($f['sort'], 'score_asc') ?>>Score (low first)</option><option value="newest"<?= selected($f['sort'], 'newest') ?>>Newest</option><option value="oldest"<?= selected($f['sort'], 'oldest') ?>>Oldest</option><option value="domain"<?= selected($f['sort'], 'domain') ?>>Domain</option></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary">Apply filters</button><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/prospects')) ?>">Reset</a></div>
</div></form>

<div class="card card-soft">
<?php if (!$rows): ?>
  <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'list-check', 'heading' => 'No prospects match', 'text' => 'Add URLs manually, import a CSV or extract links from a public page.', 'actionUrl' => $isStaff ? url('/discovery') : null, 'actionLabel' => 'Discover opportunities']) ?>
<?php else: ?>
<form method="post" action="<?= e(url('/prospects/bulk')) ?>" id="bulk-form">
  <?= csrf_field() ?>
  <?php if ($isStaff): ?>
  <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
    <span class="small text-body-secondary">Bulk action on selected:</span>
    <select name="action" class="form-select form-select-sm w-auto" aria-label="Bulk action"><option value="approve">Approve</option><option value="reject">Reject</option><option value="review">Send to review</option><option value="evaluate">Re-evaluate</option></select>
    <input name="note" class="form-control form-control-sm w-auto" placeholder="Reason (required for reject)" maxlength="500">
    <button class="btn btn-sm btn-outline-primary" data-confirm="Apply this action to the selected prospects?">Apply</button>
  </div>
  <?php endif; ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0 table-prospects">
    <thead><tr><?php if ($isStaff): ?><th><input type="checkbox" class="form-check-input" data-check-all="ids[]" aria-label="Select all"></th><?php endif; ?><th>Page</th><th>Category</th><th class="text-center">Score</th><th>Risk</th><th>Status</th><th>Discovered</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $flags = json_decode((string)$r['risk_flags'], true) ?: []; ?>
      <tr>
        <?php if ($isStaff): ?><td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select"></td><?php endif; ?>
        <td class="mw-0">
          <a class="fw-semibold d-block text-truncate" href="<?= e(url('/prospects/' . $r['id'])) ?>"><?= e($r['title'] ?: $r['domain']) ?></a>
          <span class="small text-body-secondary d-block text-truncate"><?= e($r['normalized_url']) ?></span>
          <?php if (!$f['project_id']): ?><span class="small text-body-secondary"><?= e($r['project_name']) ?></span><?php endif; ?>
        </td>
        <td class="small"><?= e(Labels::category($r['category'])) ?></td>
        <td class="text-center"><?php if ($r['quality_score'] !== null): ?><span class="score-pill score-<?= e($r['score_band']) ?>"><?= (int)$r['quality_score'] ?></span><?php else: ?><span class="text-body-secondary small">pending</span><?php endif; ?></td>
        <td><?php if ($r['manual_hold']): ?><?= badge('critical', 'Manual hold') ?><?php elseif ($r['has_critical_risk']): ?><?= badge('critical', 'Critical') ?><?php elseif ($flags): ?><span class="badge text-bg-light border"><?= count($flags) ?> flag(s)</span><?php else: ?><span class="text-body-secondary small">—</span><?php endif; ?></td>
        <td><?= badge($r['status']) ?></td>
        <td class="small text-nowrap"><?= e(fmt_date($r['discovered_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</form>
<div class="card-footer bg-transparent"><?= \BharatSeo\Http\View::partial('partials/pagination', ['pager' => $pager]) ?></div>
<?php endif; ?>
</div>
