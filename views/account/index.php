<div class="row justify-content-center"><div class="col-lg-8 vstack gap-3">
<h1 class="h4 mb-0">Account & privacy</h1>
<?php if ($account['deletion_requested_at']): ?>
  <div class="alert alert-danger d-flex justify-content-between align-items-center gap-2 flex-wrap mb-0"><span><i class="bi bi-exclamation-octagon me-1"></i>Your account will be deleted on <strong><?= e(fmt_dt(gmdate('Y-m-d H:i:s', strtotime($account['deletion_requested_at'] . ' UTC') + $graceDays * 86400), 'd M Y')) ?></strong>.</span>
    <form method="post" action="<?= e(url('/account/delete/cancel')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-light">Cancel deletion</button></form></div>
<?php endif; ?>
<?php if ($account['status'] === 'restricted'): ?><div class="alert alert-warning mb-0">Your account is restricted<?= $account['status_reason'] ? ': ' . e($account['status_reason']) : '' ?>. Contact <?= e(setting('brand.support_email')) ?> to appeal.</div><?php endif; ?>
<div class="card shadow-sm border-0"><div class="card-body">
  <h2 class="h6">Sign-in</h2>
  <p class="small text-body-secondary mb-3">Email: <strong><?= e($account['email']) ?></strong> · member since <?= e(fmt_dt($account['created_at'], 'M Y')) ?></p>
  <form method="post" action="<?= e(url('/account/password')) ?>" class="row g-2"><?= csrf_field() ?>
    <div class="col-md-4"><label class="form-label small" for="cp">Current password</label><input class="form-control<?= invalid($errors, 'current_password') ?>" type="password" id="cp" name="current_password" required autocomplete="current-password"><?= field_error($errors, 'current_password') ?></div>
    <div class="col-md-4"><label class="form-label small" for="np">New password</label><input class="form-control<?= invalid($errors, 'password') ?>" type="password" id="np" name="password" required minlength="8" autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
    <div class="col-md-4"><label class="form-label small" for="np2">Confirm</label><input class="form-control<?= invalid($errors, 'password_confirmation') ?>" type="password" id="np2" name="password_confirmation" required autocomplete="new-password"><?= field_error($errors, 'password_confirmation') ?></div>
    <div class="col-12"><button class="btn btn-outline-primary btn-sm">Change password</button></div>
  </form>
</div></div>
<?php if (!empty($currentUser) && $currentUser->isStudent()): ?>
<div class="card shadow-sm border-0"><div class="card-body">
  <h2 class="h6">Privacy</h2>
  <p class="small text-body-secondary">Choose what others can see, or pause discovery any time.</p>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/onboarding/4')) ?>">Privacy & visibility settings</a>
</div></div>
<div class="card shadow-sm border-0"><div class="card-body">
  <h2 class="h6">Blocked people</h2>
  <?php if (!$blocked): ?><p class="small text-body-secondary mb-0">You haven't blocked anyone.</p><?php else: ?>
  <ul class="list-group list-group-flush"><?php foreach ($blocked as $b): ?><li class="list-group-item d-flex justify-content-between align-items-center px-0"><span><?= e($b['display_name'] ?? 'Student') ?> <small class="text-body-secondary"><?= e(ago($b['created_at'])) ?></small></span>
    <form method="post" action="<?= e(url('/unblock/' . (int)$b['blocked_id'])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link">Unblock</button></form></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div></div>
<?php endif; ?>
<div class="card shadow-sm border-0"><div class="card-body">
  <h2 class="h6">Your data</h2>
  <p class="small text-body-secondary">Download a copy of everything we store about you (JSON).</p>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/account/export')) ?>"><i class="bi bi-download me-1"></i>Download my data</a>
</div></div>
<?php if (!$account['deletion_requested_at']): ?>
<div class="card shadow-sm border-danger-subtle"><div class="card-body">
  <h2 class="h6 text-danger">Delete account</h2>
  <p class="small text-body-secondary">Your profile and listings are hidden immediately and everything is permanently deleted after <?= (int)$graceDays ?> days. You can cancel before then.</p>
  <form method="post" action="<?= e(url('/account/delete')) ?>" class="d-flex gap-2 flex-wrap" data-confirm="Delete your account? Your profile and listings are hidden now, and everything is permanently deleted after <?= (int)$graceDays ?> days unless you cancel." data-confirm-ok="Delete my account"><?= csrf_field() ?>
    <label class="visually-hidden" for="dp">Password</label><input class="form-control form-control-sm w-auto<?= invalid($errors, 'password') ?>" type="password" id="dp" name="password" placeholder="Your password" required autocomplete="current-password">
    <button class="btn btn-sm btn-danger">Delete my account</button></form>
</div></div>
<?php endif; ?>
</div></div>
