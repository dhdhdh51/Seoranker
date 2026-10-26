<h1 class="h4 mb-1">Check your email</h1>
<p class="text-body-secondary small">We sent a 6-digit code to <strong><?= e($masked) ?></strong>. It expires in 10 minutes. Check your spam folder too.</p>
<form method="post" action="<?= e(url('/verify-email')) ?>" class="mb-3">
  <?= csrf_field() ?>
  <label class="form-label" for="code">Verification code</label>
  <input class="form-control form-control-lg text-center otp-input<?= invalid($errors, 'code') ?>" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus placeholder="••••••">
  <?= field_error($errors, 'code') ?>
  <button class="btn btn-primary w-100 mt-3">Verify email</button>
</form>
<form method="post" action="<?= e(url('/verify-email/resend')) ?>" class="text-center"><?= csrf_field() ?><button class="btn btn-link btn-sm">Send a new code</button></form>
<?= field_error($errors, 'otp') ?>
