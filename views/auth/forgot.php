<h1 class="h4 mb-1">Reset your password</h1>
<p class="text-body-secondary small">Enter your account email and we'll send you a 6-digit code.</p>
<form method="post" action="<?= e(url('/forgot-password')) ?>">
  <?= csrf_field() ?>
  <label class="form-label" for="email">Email</label>
  <input class="form-control<?= invalid($errors, 'email') ?>" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autofocus><?= field_error($errors, 'email') ?>
  <button class="btn btn-primary w-100 mt-3">Send code</button>
</form>
<p class="text-center small mt-3 mb-0"><a href="<?= e(url('/login')) ?>">Back to sign in</a></p>
