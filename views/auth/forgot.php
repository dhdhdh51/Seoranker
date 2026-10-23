<h1 class="h4 mb-2">Forgot password</h1>
<p class="small text-body-secondary">Enter your account email. If it exists, we’ll send a single-use reset link valid for <?= (int)\BharatSeo\Security\Auth::RESET_TTL_MINUTES ?> minutes.</p>
<?php if (!$smtp): ?>
  <div class="alert alert-warning small">Email delivery is not configured on this installation. Ask a Super Admin to generate a one-time reset link for you from <em>Users &amp; roles</em>.</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/forgot-password')) ?>">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="email">Email</label>
    <input class="form-control" type="email" id="email" name="email" required maxlength="190" autocomplete="email">
  </div>
  <button class="btn btn-primary w-100" type="submit">Send reset link</button>
</form>
<div class="text-center mt-3"><a class="small" href="<?= e(url('/login')) ?>">Back to sign in</a></div>
