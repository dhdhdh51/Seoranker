<?php $u = $currentUser; ?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card card-soft">
      <div class="card-body">
        <h2 class="h6 text-uppercase text-body-secondary">Account</h2>
        <dl class="row mb-0 small">
          <dt class="col-4">Name</dt><dd class="col-8"><?= e($u->name) ?></dd>
          <dt class="col-4">Email</dt><dd class="col-8"><?= e($u->email) ?></dd>
          <dt class="col-4">Role</dt><dd class="col-8"><?= e($u->roleLabel()) ?></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card card-soft">
      <div class="card-body">
        <h2 class="h6 text-uppercase text-body-secondary">Change password</h2>
        <form method="post" action="<?= e(url('/profile/password')) ?>" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-12"><label class="form-label" for="cp">Current password</label><input id="cp" class="form-control" type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="col-md-6"><label class="form-label" for="np">New password</label><input id="np" class="form-control" type="password" name="password" required minlength="10" autocomplete="new-password"></div>
          <div class="col-md-6"><label class="form-label" for="np2">Confirm</label><input id="np2" class="form-control" type="password" name="password_confirmation" required autocomplete="new-password"></div>
          <div class="col-12"><button class="btn btn-primary">Update password</button></div>
        </form>
      </div>
    </div>
  </div>
</div>
