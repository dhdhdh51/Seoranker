<?php
use BharatSeo\Services\DashboardService;
use BharatSeo\Services\QualityScorer;
use BharatSeo\Support\Labels;
$id = (int)$p['id'];
$components = $assessment ? (json_decode((string)$assessment['components'], true) ?: []) : [];
?>
<div class="page-head">
  <div class="min-w-0">
    <div class="small text-body-secondary"><a href="<?= e(url('/projects/' . $p['project_id'])) ?>"><?= e($p['project_name']) ?></a> · <?= e(Labels::category($p['category'])) ?> · <?= e(Labels::SOURCES[$p['discovery_source']] ?? '') ?></div>
    <h1 class="h4 mb-1 text-break"><?= e($p['title'] ?: $p['domain']) ?></h1>
    <div class="small text-break"><?= ext_link($p['normalized_url'], null, 120) ?></div>
  </div>
  <div class="text-end">
    <?= badge($p['status']) ?>
    <?php if ($p['quality_score'] !== null): ?><div class="score-big score-<?= e($p['score_band']) ?> mt-2" title="Internal heuristic, not DA/DR"><?= (int)$p['quality_score'] ?><small>/100</small></div><div class="small text-body-secondary"><?= e(QualityScorer::bandLabel((string)$p['score_band'])) ?></div><?php endif; ?>
  </div>
</div>

<?php if ($p['manual_hold']): ?>
  <div class="alert alert-danger"><i class="bi bi-shield-exclamation"></i> <strong>Manual hold:</strong> <?= e($p['hold_reason']) ?>. Approval is blocked until a manager reviews the critical risk and releases the hold with a documented reason — even if the score is high.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <?php if ($canManage): ?>
    <div class="card card-soft mb-3"><div class="card-body d-flex flex-wrap gap-2">
      <?php if ($p['status'] === 'needs_review' || $p['status'] === 'evaluated'): ?>
        <?php if ($p['status'] === 'needs_review' && !$p['manual_hold']): ?>
          <form method="post" action="<?= e(url('/prospects/' . $id . '/decision')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button class="btn btn-success"><i class="bi bi-check-lg"></i> Approve</button></form>
        <?php endif; ?>
        <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#reject-box" type="button"><i class="bi bi-x-lg"></i> Reject</button>
        <?php if ($p['status'] === 'evaluated'): ?><form method="post" action="<?= e(url('/prospects/' . $id . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="needs_review"><button class="btn btn-outline-primary">Send to review</button></form><?php endif; ?>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/prospects/' . $id . '/evaluate')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Re-evaluate</button></form>
      <form method="post" action="<?= e(url('/prospects/' . $id . '/scan-broken')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary"><i class="bi bi-link-45deg"></i> Scan for broken links</button></form>
      <?php if (in_array($p['status'], ['approved', 'outreach_sent', 'submitted'], true)): ?>
        <form method="post" action="<?= e(url('/prospects/' . $id . '/track')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary"><i class="bi bi-binoculars"></i> Track backlink</button></form>
      <?php endif; ?>
      <?php if (in_array($p['status'], ['rejected', 'failed'], true)): ?>
        <form method="post" action="<?= e(url('/prospects/' . $id . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="needs_review"><button class="btn btn-outline-primary">Reopen for review</button></form>
      <?php endif; ?>
      <?php if (in_array($p['status'], ['outreach_sent', 'submitted', 'approved'], true)): ?>
        <form method="post" action="<?= e(url('/prospects/' . $id . '/status')) ?>" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="status" value="failed"><input type="hidden" name="note" value="Site declined or no response"><button class="btn btn-outline-secondary" data-confirm="Mark this opportunity as failed?">Mark failed</button></form>
      <?php endif; ?>
    </div></div>
    <div class="collapse" id="reject-box"><div class="card card-soft mb-3"><div class="card-body">
      <form method="post" action="<?= e(url('/prospects/' . $id . '/decision')) ?>" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="decision" value="reject"><input name="note" class="form-control" required minlength="3" placeholder="Reason for rejection"><button class="btn btn-danger">Reject</button></form>
    </div></div></div>
    <?php endif; ?>

    <?php if ($flags): ?>
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Risk flags</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($flags as $fl): ?><li class="list-group-item d-flex gap-2 align-items-start"><?= badge($fl['severity'] === 'critical' ? 'critical' : ($fl['severity'] === 'warning' ? 'warning' : 'info'), ucfirst($fl['severity'])) ?><span class="small"><strong><?= e($fl['code']) ?></strong> — <?= e($fl['message']) ?></span></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card card-soft mb-3">
      <div class="card-header bg-transparent d-flex justify-content-between"><span class="fw-semibold">Score breakdown</span><a class="small" href="<?= e(url('/prospects/' . $id . '/assessment')) ?>">Full assessment &amp; history →</a></div>
      <div class="card-body">
        <?php if (!$components): ?>
          <div class="skeleton-row"><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line w-75"></div></div>
          <p class="small text-body-secondary mb-0 mt-2">Evaluation is queued. It runs when the queue cron processes the job<?= app()->make(\BharatSeo\Services\CronService::class)->isAutomationActive() ? '' : ' — <strong>cron is not active</strong>, so nothing will happen until it is configured' ?>.</p>
        <?php else: foreach ($components as $c): $pct = $c['max'] ? round($c['score'] / $c['max'] * 100) : 0; ?>
          <div class="mb-3">
            <div class="d-flex justify-content-between small"><span class="fw-semibold"><?= e($c['label']) ?><?= $c['status'] === 'unknown' ? ' · <span class="badge text-bg-secondary">Unknown</span>' : ($c['status'] === 'partial' ? ' · <span class="badge text-bg-light border">Partly unknown</span>' : '') ?></span><span><?= (int)$c['score'] ?> / <?= (int)$c['max'] ?></span></div>
            <div class="progress progress-thin" role="progressbar" aria-valuenow="<?= (int)$pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width: <?= (int)$pct ?>%"></div></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Details &amp; evidence</div><div class="card-body">
      <?php if ($canManage): ?>
      <form method="post" action="<?= e(url('/prospects/' . $id)) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small">Category</label><select name="category" class="form-select form-select-sm"><?php foreach (Labels::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($p['category'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small">Proposed target page</label><select name="proposed_target_page_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($targets as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($p['proposed_target_page_id'], $t['id']) ?>><?= e(str_limit($t['url'], 70)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small">Public professional contact email</label><input name="contact_email" type="email" class="form-control form-control-sm" value="<?= e($p['contact_email']) ?>"></div>
        <div class="col-md-6"><label class="form-label small">Contact name (only if publicly provided)</label><input name="contact_name" class="form-control form-control-sm" value="<?= e($p['contact_name']) ?>" maxlength="160"></div>
        <div class="col-md-6"><label class="form-label small">Suggested natural anchor</label><input name="suggested_anchor" class="form-control form-control-sm" value="<?= e($p['suggested_anchor']) ?>" maxlength="255"></div>
        <div class="col-md-6"><label class="form-label small">Official submission page</label><input name="submission_url" class="form-control form-control-sm" value="<?= e($p['submission_url']) ?>"></div>
        <div class="col-12"><label class="form-label small">Evidence</label><textarea name="evidence" class="form-control form-control-sm" rows="4" maxlength="5000"><?= e($p['evidence']) ?></textarea></div>
        <div class="col-12"><label class="form-label small">Notes</label><textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="5000"><?= e($p['notes']) ?></textarea></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Save details</button></div>
      </form>
      <?php else: ?>
        <dl class="row small mb-0">
          <dt class="col-sm-4">Suggested anchor</dt><dd class="col-sm-8"><?= e($p['suggested_anchor'] ?: '—') ?></dd>
          <dt class="col-sm-4">Target page</dt><dd class="col-sm-8"><?= e($p['target_url'] ?: '—') ?></dd>
          <dt class="col-sm-4">Evidence</dt><dd class="col-sm-8"><?= nl2br(e($p['evidence'] ?: '—')) ?></dd>
        </dl>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-xl-4">
    <div class="card card-soft mb-3"><div class="card-body small">
      <h2 class="h6 text-uppercase text-body-secondary">Page facts</h2>
      <dl class="row mb-0">
        <dt class="col-5">Domain</dt><dd class="col-7 text-break"><?= e($p['domain']) ?></dd>
        <dt class="col-5">Site name</dt><dd class="col-7"><?= e($p['site_name'] ?: 'Unknown') ?></dd>
        <dt class="col-5">HTTP status</dt><dd class="col-7"><?= $p['http_status'] ? (int)$p['http_status'] : 'Unknown' ?></dd>
        <dt class="col-5">Final URL</dt><dd class="col-7 text-break"><?= e($p['final_url'] ?: '—') ?></dd>
        <dt class="col-5">Canonical</dt><dd class="col-7 text-break"><?= e($p['canonical_url'] ?: '—') ?></dd>
        <dt class="col-5">Words</dt><dd class="col-7"><?= $p['word_count'] !== null ? num((int)$p['word_count']) : 'Unknown' ?></dd>
        <dt class="col-5">Outbound links</dt><dd class="col-7"><?= $p['outbound_link_count'] !== null ? num((int)$p['outbound_link_count']) : 'Unknown' ?></dd>
        <dt class="col-5">Last fetched</dt><dd class="col-7"><?= e(fmt_dt($p['last_fetched_at'])) ?></dd>
        <?php if ($p['fetch_error']): ?><dt class="col-5">Fetch error</dt><dd class="col-7 text-danger"><?= e($p['fetch_error']) ?></dd><?php endif; ?>
        <dt class="col-5">Discovered</dt><dd class="col-7"><?= e(fmt_dt($p['discovered_at'])) ?></dd>
        <?php if ($currentUser->isStaff()): ?><dt class="col-5">Contact</dt><dd class="col-7 text-break"><?= e($p['contact_email'] ?: 'None publicly listed') ?></dd><?php endif; ?>
        <?php if ($p['contact_page_url']): ?><dt class="col-5">Contact page</dt><dd class="col-7"><?= ext_link($p['contact_page_url'], 'open', 30) ?></dd><?php endif; ?>
      </dl>
    </div></div>

    <?php if ($canManage && in_array($p['category'], ['business_directory', 'local_citation', 'community_profile', 'industry_association'], true)): ?>
    <div class="card card-soft mb-3"><div class="card-body small">
      <h2 class="h6 text-uppercase text-body-secondary">Manual submission</h2>
      <p>Directory, citation and profile sites are <strong>submitted by you</strong> on their official page. <?= e(brand()) ?> never creates accounts, solves CAPTCHAs or bypasses verification.</p>
      <?php if ($p['submission_url']): ?><a class="btn btn-sm btn-outline-primary mb-2" href="<?= e($p['submission_url']) ?>" target="_blank" rel="noopener noreferrer nofollow"><i class="bi bi-box-arrow-up-right"></i> Open official submission page</a><?php else: ?><p class="text-body-secondary">No submission page recorded — add it under Details.</p><?php endif; ?>
      <?php if ($p['status'] === 'approved'): ?>
      <form method="post" action="<?= e(url('/prospects/' . $id . '/submitted')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><input name="external_submission_id" class="form-control form-control-sm" placeholder="Listing / reference ID (optional, unique)" maxlength="190"></div>
        <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="Note" maxlength="500"></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">I submitted it manually</button></div>
      </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($canManage && $p['status'] === 'approved' && $campaigns): ?>
    <div class="card card-soft mb-3"><div class="card-body small">
      <h2 class="h6 text-uppercase text-body-secondary">Draft outreach</h2>
      <?php foreach ($campaigns as $c): if ($c['status'] === 'completed') { continue; } ?>
        <form method="post" action="<?= e(url('/outreach/campaigns/' . $c['id'] . '/drafts')) ?>" class="mb-1"><?= csrf_field() ?><input type="hidden" name="prospect_ids[]" value="<?= $id ?>"><button class="btn btn-sm btn-outline-primary w-100 text-start"><i class="bi bi-envelope-plus"></i> <?= e($c['name']) ?></button></form>
      <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($messages): ?>
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold small">Outreach messages</div>
      <ul class="list-group list-group-flush small"><?php foreach ($messages as $m): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/outreach/messages/' . $m['id'])) ?>" class="text-truncate"><?= e($m['subject']) ?></a><?= badge($m['status']) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <?php if ($backlinks): ?>
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold small">Tracked backlinks</div>
      <ul class="list-group list-group-flush small"><?php foreach ($backlinks as $b): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/backlinks/' . $b['id'])) ?>">#<?= (int)$b['id'] ?> → <?= e(str_limit($b['target_url'], 40)) ?></a><?= badge($b['status']) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <?php if ($p['manual_hold'] && $canManage): ?>
    <div class="card card-soft border-danger mb-3"><div class="card-body small">
      <h2 class="h6 text-danger">Release manual hold</h2>
      <form method="post" action="<?= e(url('/prospects/' . $id . '/hold-release')) ?>"><?= csrf_field() ?><textarea name="reason" class="form-control form-control-sm mb-2" rows="3" required minlength="10" placeholder="Why is the critical risk acceptable? (recorded in the audit log)"></textarea><button class="btn btn-sm btn-outline-danger" data-confirm="Release the hold? This is recorded with your name.">Release hold</button></form>
    </div></div>
    <?php endif; ?>

    <?php if ($timeline): ?>
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold small">Timeline</div>
      <ul class="list-group list-group-flush small"><?php foreach ($timeline as $t): $d = json_decode((string)$t['details'], true) ?: []; ?><li class="list-group-item"><div class="d-flex justify-content-between"><span><?= e(DashboardService::actionLabel($t['action'])) ?></span><span class="text-body-secondary"><?= e(fmt_dt($t['created_at'])) ?></span></div><div class="text-body-secondary"><?= e($t['user_name'] ?? 'System') ?><?= isset($d['from'], $d['to']) ? ' · ' . e($d['from'] . ' → ' . $d['to']) : '' ?><?= !empty($d['reason']) ? ' · ' . e(str_limit((string)$d['reason'], 80)) : '' ?></div></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
  </div>
</div>
