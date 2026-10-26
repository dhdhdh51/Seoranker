<a class="small" href="<?= e(url('/admin/reports')) ?>"><i class="bi bi-arrow-left"></i> Reports</a>
<h1 class="h4 mt-2">Report #<?= (int)$r['id'] ?> · <?= e(label('report_reason', $r['reason'])) ?> <?= $r['severity'] === 'serious' ? '<span class="badge text-bg-danger">serious</span>' : '' ?></h1>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-sm-4">Reported</dt><dd class="col-sm-8"><?= e($r['target_type']) ?> #<?= (int)$r['target_id'] ?> by <?php if ($currentUser->can('users') && $r['target_user_id']): ?><a href="<?= e(url('/admin/users/' . (int)$r['target_user_id'])) ?>"><?= e($r['target_name'] ?? $r['target_legal_name']) ?></a><?php else: ?><?= e($r['target_name'] ?? '—') ?><?php endif; ?> (<?= e($r['target_status'] ?? '') ?>)</dd>
        <dt class="col-sm-4">Reporter</dt><dd class="col-sm-8"><?= e($r['reporter_name'] ?? '—') ?></dd>
        <dt class="col-sm-4">When</dt><dd class="col-sm-8"><?= e(fmt_dt($r['created_at'])) ?></dd>
        <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= e($r['status']) ?><?= $r['decision_note'] ? ' — ' . e($r['decision_note']) : '' ?></dd>
        <dt class="col-sm-4">Details</dt><dd class="col-sm-8 text-pre"><?= e($r['details'] ?? '—') ?></dd>
      </dl>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Evidence</div><div class="card-body small">
      <?php if (!empty($evidence['messages'])): foreach ($evidence['messages'] as $m): ?>
        <div class="p-2 mb-1 rounded <?= (int)$m['id'] === $evidence['focus'] ? 'bg-danger-subtle' : 'bg-body-tertiary' ?>"><strong><?= e($m['display_name']) ?></strong> <span class="text-body-secondary"><?= e(fmt_dt(substr((string)$m['created_at'], 0, 19))) ?></span><?= $m['hidden_at'] ? ' <span class="badge text-bg-secondary">hidden</span>' : '' ?><div class="text-pre"><?= e($m['body']) ?></div></div>
      <?php endforeach; elseif (!empty($evidence['listing'])): $l = $evidence['listing']; ?>
        <h2 class="h6"><a href="<?= e(url('/rooms/' . $l['slug'])) ?>"><?= e($l['title']) ?></a> <span class="badge text-bg-secondary"><?= e($l['status']) ?></span></h2><p><?= e(inr($l['rent'])) ?> · <?= e($l['locality']) ?></p><div class="text-pre"><?= e(str_limit($l['description'], 1200)) ?></div>
      <?php elseif (!empty($evidence['profile'])): $p = $evidence['profile']; ?>
        <div class="d-flex gap-2 align-items-center"><?= avatar($p['photo_path'], (string)$p['display_name'], 'lg') ?><strong><?= e($p['display_name']) ?></strong></div><div class="text-pre mt-2"><?= e($p['bio'] ?? '') ?></div>
      <?php else: ?><p class="text-body-secondary mb-0">The content no longer exists.</p><?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <?php if (in_array($r['status'], ['open', 'escalated'], true)): ?>
    <form method="post" action="<?= e(url('/admin/reports/' . (int)$r['id'])) ?>" class="card border-0 shadow-sm"><div class="card-body">
      <?= csrf_field() ?>
      <h2 class="h6">Decision</h2>
      <?php foreach (['dismiss' => 'Dismiss — no violation', 'warn' => 'Warn the user', 'hide_content' => 'Hide the content', 'restrict' => 'Restrict account (browse only)', 'suspend' => 'Suspend account', 'escalate' => 'Escalate to Super Admin'] as $k => $v):
          if (in_array($k, ['restrict', 'suspend'], true) && !$currentUser->can('users')) continue; ?>
        <div class="form-check"><input class="form-check-input" type="radio" name="action" value="<?= e($k) ?>" id="a_<?= e($k) ?>" required><label class="form-check-label" for="a_<?= e($k) ?>"><?= e($v) ?></label></div>
      <?php endforeach; ?>
      <label class="form-label mt-2 small" for="note">Note (shown to the user for restrictions)</label>
      <textarea class="form-control form-control-sm" id="note" name="note" rows="3" maxlength="1000"></textarea>
      <button class="btn btn-primary mt-3 w-100">Save decision</button>
    </div></form>
    <?php endif; ?>
  </div>
</div>
