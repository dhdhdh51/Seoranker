<?php
use BharatSeo\Security\User;
$x = $u ?? [];
$v = static fn(string $k, $d = '') => old($k, $x[$k] ?? $d);
?>
<div class="page-head"><h1 class="h3 mb-0"><?= e($title) ?></h1></div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card card-soft"><div class="card-body">
      <form method="post" action="<?= e(url($u ? '/users/' . $u['id'] : '/users')) ?>" class="row g-3" autocomplete="off">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label" for="name">Name *</label><input id="name" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" name="name" value="<?= e($v('name')) ?>" required maxlength="120"><div class="invalid-feedback"><?= e($errors['name'] ?? '') ?></div></div>
        <div class="col-md-6"><label class="form-label" for="email">Email *</label><input id="email" type="email" class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" name="email" value="<?= e($v('email')) ?>" required maxlength="190"><div class="invalid-feedback"><?= e($errors['email'] ?? '') ?></div></div>
        <div class="col-md-6"><label class="form-label" for="role">Role</label><select id="role" name="role" class="form-select<?= isset($errors['role']) ? ' is-invalid' : '' ?>"><?php foreach (User::ROLES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($v('role', 'seo_manager'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><div class="invalid-feedback"><?= e($errors['role'] ?? '') ?></div></div>
        <div class="col-md-6"><label class="form-label" for="status">Status</label><select id="status" name="status" class="form-select"><option value="active"<?= selected($v('status', 'active'), 'active') ?>>Active</option><option value="disabled"<?= selected($v('status'), 'disabled') ?>>Disabled</option></select></div>
        <div class="col-12"><label class="form-label" for="password"><?= $u ? 'New password (leave blank to keep)' : 'Initial password *' ?></label><input id="password" type="password" class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" name="password" <?= $u ? '' : 'required' ?> minlength="10" autocomplete="new-password"><div class="invalid-feedback"><?= e($errors['password'] ?? '') ?></div><div class="form-text">At least 10 characters with letters and a number or symbol. Changing it signs the user out everywhere.</div></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save user</button><a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>">Back</a></div>
      </form>
    </div></div>
  </div>
  <?php if ($u): ?>
  <div class="col-lg-5">
    <div class="card card-soft mb-3"><div class="card-body small">
      <h2 class="h6">Access</h2>
      <?php if ($u['role'] === 'client'): ?>
        <?php foreach ($clientLinks as $c): ?><div><a href="<?= e(url('/clients/' . $c['client_id'])) ?>"><?= e($c['name']) ?></a> <?= $c['can_approve'] ? badge('approved', 'approve') : '' ?> <?= $c['can_export'] ? badge('info', 'export') : '' ?></div><?php endforeach; ?>
        <?php if (!$clientLinks): ?><p class="mb-0">Not linked to a client yet — open a client and grant access.</p><?php endif; ?>
      <?php elseif ($u['role'] === 'seo_manager'): ?>
        <?php foreach ($projectLinks as $pl): ?><div><a href="<?= e(url('/projects/' . $pl['project_id'])) ?>"><?= e($pl['name']) ?></a> · <?= e($pl['role']) ?></div><?php endforeach; ?>
        <?php if (!$projectLinks): ?><p class="mb-0">No projects assigned — add the manager from a project’s Team card.</p><?php endif; ?>
      <?php else: ?><p class="mb-0">Super Admins can access everything.</p><?php endif; ?>
    </div></div>
    <div class="card card-soft"><div class="card-body small">
      <h2 class="h6">Password reset link</h2>
      <p>If SMTP is unavailable, generate a one-time link (valid <?= (int)\BharatSeo\Security\Auth::RESET_TTL_MINUTES ?> minutes) and share it over a secure channel.</p>
      <?php if ($resetLink): ?><div class="input-group input-group-sm mb-2"><input class="form-control" id="reset-link" value="<?= e($resetLink) ?>" readonly><button class="btn btn-outline-secondary" type="button" data-copy-target="#reset-link">Copy</button></div><p class="text-danger mb-2">Shown once. It is not stored in plaintext.</p><?php endif; ?>
      <form method="post" action="<?= e(url('/users/' . $u['id'] . '/reset-link')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Generate reset link</button></form>
    </div></div>
  </div>
  <?php endif; ?>
</div>
