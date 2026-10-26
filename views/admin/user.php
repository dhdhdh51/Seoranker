<?php use Flatmates\Security\User; $perms = explode(',', (string)$u['permissions']); ?>
<a class="small" href="<?= e(url('/admin/users')) ?>"><i class="bi bi-arrow-left"></i> Users</a>
<h1 class="h4 mt-2"><?= e($u['name']) ?> <span class="badge text-bg-secondary"><?= e(label('role', $u['role'])) ?></span> <span class="badge text-bg-<?= $u['status'] === 'active' ? 'success' : 'warning' ?>"><?= e($u['status']) ?></span></h1>
<div class="row g-3">
  <div class="col-lg-7 vstack gap-3">
    <div class="card border-0 shadow-sm"><div class="card-body small"><dl class="row mb-0">
      <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($u['email']) ?> <?= $u['email_verified_at'] ? '<i class="bi bi-check-circle text-success" title="verified"></i>' : '(unverified)' ?></dd>
      <dt class="col-sm-4">Display name</dt><dd class="col-sm-8"><?= e($u['profile']['display_name'] ?? '—') ?><?php if ($u['profile']): ?> · <a href="<?= e(url('/profile/' . (int)$u['id'])) ?>">view profile</a><?php endif; ?></dd>
      <dt class="col-sm-4">College / city</dt><dd class="col-sm-8"><?= e(($u['profile']['college_name'] ?? '—') . ' / ' . ($u['profile']['city_name'] ?? '—')) ?></dd>
      <dt class="col-sm-4">Joined</dt><dd class="col-sm-8"><?= e(fmt_dt($u['created_at'])) ?>, last login <?= e(fmt_dt($u['last_login_at'])) ?></dd>
      <?php if ($u['status_reason']): ?><dt class="col-sm-4">Status reason</dt><dd class="col-sm-8"><?= e($u['status_reason']) ?></dd><?php endif; ?>
      <?php if ($u['deletion_requested_at']): ?><dt class="col-sm-4">Deletion requested</dt><dd class="col-sm-8"><?= e(fmt_dt($u['deletion_requested_at'])) ?></dd><?php endif; ?>
      <dt class="col-sm-4">Verification</dt><dd class="col-sm-8"><?php foreach ($u['verifications'] as $v): ?><div><?= e($v['method']) ?>: <?= e($v['status']) ?> (<?= e(ago($v['created_at'])) ?>)</div><?php endforeach; ?><?= $u['verifications'] ? '' : '—' ?></dd>
      <dt class="col-sm-4">Reports made</dt><dd class="col-sm-8"><?= (int)$u['reports_made'] ?></dd>
    </dl></div></div>
    <div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Reports against (<?= count($u['reports_against']) ?>)</div><ul class="list-group list-group-flush small">
      <?php foreach ($u['reports_against'] as $r): ?><li class="list-group-item"><a href="<?= e(url('/admin/reports/' . (int)$r['id'])) ?>">#<?= (int)$r['id'] ?></a> <?= e(label('report_reason', $r['reason'])) ?> · <?= e($r['target_type']) ?> · <?= e($r['status']) ?> <?= $r['severity'] === 'serious' ? '<span class="badge text-bg-danger">serious</span>' : '' ?> <span class="text-body-secondary"><?= e(ago($r['created_at'])) ?></span></li><?php endforeach; ?>
      <?php if (!$u['reports_against']): ?><li class="list-group-item text-body-secondary">None</li><?php endif; ?></ul></div>
    <?php if ($u['listings']): ?><div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Listings</div><ul class="list-group list-group-flush small"><?php foreach ($u['listings'] as $l): ?><li class="list-group-item"><a href="<?= e(url('/rooms/' . $l['slug'])) ?>"><?= e($l['title']) ?></a> · <?= e($l['status']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Recent activity</div><ul class="list-group list-group-flush small"><?php foreach ($u['audit'] as $a): ?><li class="list-group-item d-flex justify-content-between"><code><?= e($a['action']) ?></code><span class="text-body-secondary"><?= e(fmt_dt($a['created_at'])) ?></span></li><?php endforeach; ?></ul></div>
  </div>
  <div class="col-lg-5 vstack gap-3">
    <?php if ((int)$u['id'] !== $currentUser->id && $u['status'] !== 'deleted' && ($u['role'] === 'student' || $currentUser->isAdmin())): ?>
    <form method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/status')) ?>" class="card border-0 shadow-sm"><div class="card-body"><?= csrf_field() ?>
      <h2 class="h6">Account status</h2>
      <select class="form-select form-select-sm mb-2" name="status" aria-label="Status"><option value="active"<?= selected($u['status'], 'active') ?>>Active</option><option value="restricted"<?= selected($u['status'], 'restricted') ?>>Restricted (browse only)</option><option value="suspended"<?= selected($u['status'], 'suspended') ?>>Suspended (cannot sign in)</option></select>
      <label class="form-label small" for="reason">Reason (shown to the user)</label>
      <textarea class="form-control form-control-sm<?= invalid($errors, 'reason') ?>" id="reason" name="reason" rows="2" maxlength="500"><?= e($u['status_reason'] ?? '') ?></textarea><?= field_error($errors, 'reason') ?>
      <button class="btn btn-sm btn-primary mt-2">Update status</button>
    </div></form>
    <?php endif; ?>
    <?php if ($currentUser->isAdmin() && (int)$u['id'] !== $currentUser->id && $u['status'] !== 'deleted'): ?>
    <form method="post" action="<?= e(url('/admin/users/' . (int)$u['id'] . '/role')) ?>" class="card border-0 shadow-sm"><div class="card-body"><?= csrf_field() ?>
      <h2 class="h6">Role & permissions</h2>
      <select class="form-select form-select-sm mb-2" name="role" aria-label="Role"><?php foreach (\Flatmates\Support\Options::GROUPS['role'] as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($u['role'], $k) ?>><?= e($v) ?></option><?php endforeach; ?></select>
      <div class="small mb-2">Moderator permissions:<?php foreach (User::PERMISSIONS as $k => $v): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($k) ?>" id="p_<?= e($k) ?>"<?= checked(in_array($k, $perms, true)) ?>><label class="form-check-label" for="p_<?= e($k) ?>"><?= e($v) ?></label></div><?php endforeach; ?></div>
      <button class="btn btn-sm btn-outline-primary" data-confirm-click="Change this user's role?">Save role</button>
    </div></form>
    <?php endif; ?>
  </div>
</div>
