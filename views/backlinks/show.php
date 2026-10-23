<?php use BharatSeo\Support\Labels; ?>
<div class="page-head">
  <div class="min-w-0">
    <div class="small"><a href="<?= e(url('/backlinks', ['project_id' => $b['project_id']])) ?>">← <?= e($b['project_name']) ?> backlinks</a></div>
    <h1 class="h4 mb-0 text-break"><?= e($b['referring_domain']) ?> <?= badge($b['status']) ?> <?= badge($b['lifecycle']) ?></h1>
    <div class="small text-break"><?= ext_link($b['referring_page_url'], null, 120) ?></div>
  </div>
  <?php if ($canManage): ?>
  <div class="d-flex gap-2">
    <form method="post" action="<?= e(url('/backlinks/' . $b['id'] . '/check')) ?>"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Check now</button></form>
    <form method="post" action="<?= e(url('/backlinks/' . $b['id'] . '/delete')) ?>" data-confirm="Delete this record and its history?"><?= csrf_field() ?><button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button></form>
  </div>
  <?php endif; ?>
</div>
<div class="alert alert-light border small">Observed backlink data comes from fetching the referring page with our crawler. A link we observe may or may not be indexed or counted by any search engine; we do not have access to Google’s complete backlink index.</div>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card card-soft mb-3"><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-5">Target</dt><dd class="col-7 text-break"><?= ext_link($b['target_url'], null, 80) ?></dd>
        <dt class="col-5">Expected anchor</dt><dd class="col-7"><?= e($b['expected_anchor'] ?: '—') ?></dd>
        <dt class="col-5">Observed anchor</dt><dd class="col-7"><?= e($b['observed_anchor'] ?: '—') ?></dd>
        <dt class="col-5">rel attributes</dt><dd class="col-7"><?= $b['rel_attributes'] ? '<code>' . e($b['rel_attributes']) . '</code>' : 'none (followed)' ?></dd>
        <dt class="col-5">Link in fetched HTML</dt><dd class="col-7"><?= $b['link_present'] === null ? 'Unknown' : ($b['link_present'] ? 'Yes' : 'No') ?></dd>
        <dt class="col-5">Last HTTP status</dt><dd class="col-7"><?= $b['http_status'] ? (int)$b['http_status'] : '—' ?></dd>
        <dt class="col-5">First detected</dt><dd class="col-7"><?= e(fmt_dt($b['first_detected_at'])) ?></dd>
        <dt class="col-5">Last verified</dt><dd class="col-7"><?= e(fmt_dt($b['last_verified_at'])) ?></dd>
        <dt class="col-5">Last checked</dt><dd class="col-7"><?= e(fmt_dt($b['last_checked_at'])) ?></dd>
        <dt class="col-5">Next check</dt><dd class="col-7"><?= e(fmt_dt($b['next_check_at'])) ?></dd>
        <dt class="col-5">Pending confirmation</dt><dd class="col-7"><?= $b['pending_observation'] ? e($b['pending_observation']) . ' (' . (int)$b['consecutive_negative'] . '×)' : '—' ?></dd>
        <dt class="col-5">Consecutive errors</dt><dd class="col-7"><?= (int)$b['consecutive_failures'] ?></dd>
        <?php if ($b['last_error']): ?><dt class="col-5">Last note</dt><dd class="col-7 text-break"><?= e($b['last_error']) ?></dd><?php endif; ?>
        <dt class="col-5">Source</dt><dd class="col-7"><?= e($b['source']) ?><?= $b['prospect_page_id'] ? ' · <a href="' . e(url('/prospects/' . $b['prospect_page_id'])) . '">prospect</a>' : '' ?></dd>
      </dl>
    </div></div>
    <?php if ($canManage): ?>
    <div class="card card-soft"><div class="card-body">
      <form method="post" action="<?= e(url('/backlinks/' . $b['id'])) ?>" class="d-flex gap-2"><?= csrf_field() ?><input name="expected_anchor" class="form-control form-control-sm" value="<?= e($b['expected_anchor']) ?>" placeholder="Expected anchor" maxlength="255"><button class="btn btn-sm btn-outline-primary">Save</button></form>
    </div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-7">
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Check history</div>
      <?php if (!$history): ?><div class="card-body small text-body-secondary">Not checked yet.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Checked</th><th>HTTP</th><th>Observation</th><th>Status</th><th>Anchor / note</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?><tr class="<?= $h['status_changed'] ? 'table-warning' : '' ?>"><td class="small text-nowrap"><?= e(fmt_dt($h['checked_at'])) ?></td><td class="small"><?= $h['http_status'] ? (int)$h['http_status'] : '—' ?></td><td class="small"><?= e($h['observation']) ?></td><td class="small text-nowrap"><?= e(Labels::BACKLINK_STATUSES[$h['status_before']] ?? $h['status_before']) ?><?= $h['status_changed'] ? ' → <strong>' . e(Labels::BACKLINK_STATUSES[$h['status_after']] ?? $h['status_after']) . '</strong>' : '' ?></td><td class="small text-break"><?= e(str_limit($h['observed_anchor'] ?? $h['error'] ?? '', 80)) ?></td></tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
</div>
