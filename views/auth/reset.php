<h1 class="h4 mb-3">Choose a new password</h1>
<form method="post" action="<?= e(url('/reset-password')) ?>">
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control" id="email" type="email" name="email" value="<?= e($email ?: old('email')) ?>" required></div>
  <div class="mb-3"><label class="form-label" for="code">6-digit code</label><input class="form-control otp-input<?= invalid($errors, 'code') ?>" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required><?= field_error($errors, 'code') ?></div>
  <div class="mb-3"><label class="form-label" for="password">New password</label><input class="form-control<?= invalid($errors, 'password') ?>" id="password" type="password" name="password" minlength="8" required autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
  <div class="mb-3"><label class="form-label" for="password2">Confirm new password</label><input class="form-control<?= invalid($errors, 'password_confirmation') ?>" id="password2" type="password" name="password_confirmation" required autocomplete="new-password"><?= field_error($errors, 'password_confirmation') ?></div>
  <button class="btn btn-primary w-100">Change password</button>
</form>
<p class="text-center small mt-3 mb-0"><a href="<?= e(url('/forgot-password')) ?>">Didn't get a code?</a></p>
