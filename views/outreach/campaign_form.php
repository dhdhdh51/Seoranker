<?php
use BharatSeo\Support\Labels;
$c = $campaign ?? [];
$v = static fn(string $k, $d = '') => old($k, $c[$k] ?? $d);
?>
<div class="page-head"><h1 class="h3 mb-0"><?= e($title) ?></h1></div>
<div class="card card-soft"><div class="card-body">
<form method="post" action="<?= e(url($campaign ? '/outreach/campaigns/' . $campaign['id'] : '/outreach/campaigns')) ?>" class="row g-3">
  <?= csrf_field() ?>
  <?php if (!$campaign): ?>
  <div class="col-md-6"><label class="form-label">Project *</label><select name="project_id" class="form-select" required><option value="">Choose…</option><?php foreach ($projects as $id => $l): ?><option value="<?= (int)$id ?>"<?= selected($projectId ?? '', $id) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <div class="col-md-6"><label class="form-label" for="name">Campaign name *</label><input id="name" name="name" class="form-control" value="<?= e($v('name')) ?>" required maxlength="190"></div>
  <div class="col-md-6"><label class="form-label" for="type">Template type</label><select id="type" name="type" class="form-select"><?php foreach (Labels::CAMPAIGN_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($v('type', 'resource_page'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label" for="status">Status</label><select id="status" name="status" class="form-select"><?php foreach (['draft', 'active', 'paused', 'completed'] as $s): ?><option value="<?= $s ?>"<?= selected($v('status', 'draft'), $s) ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label" for="fud">Follow-up after (days)</label><input id="fud" type="number" min="2" max="60" name="follow_up_days" class="form-control" value="<?= e($v('follow_up_days', 7)) ?>"></div>
  <div class="col-md-3"><label class="form-label" for="mfu">Max follow-ups</label><input id="mfu" type="number" min="0" max="2" name="max_follow_ups" class="form-control" value="<?= e($v('max_follow_ups', 1)) ?>"></div>
  <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="auto_drafts" value="1" id="ad"<?= checked($v('auto_drafts', 1)) ?>><label class="form-check-label" for="ad">Auto-draft for newly approved prospects (drafts still need approval)</label></div></div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save campaign</button><a class="btn btn-outline-secondary" href="<?= e(url('/outreach')) ?>">Cancel</a></div>
</form>
</div></div>
