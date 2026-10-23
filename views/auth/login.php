<h1 class="h4 mb-3">Sign in</h1>
<form method="post" action="<?= e(url('/login')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="email">Email</label>
    <input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" autocomplete="username" required autofocus maxlength="190">
  </div>
  <div class="mb-3">
    <label class="form-label" for="password">Password</label>
    <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required maxlength="200">
  </div>
  <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> Sign in</button>
</form>
<div class="text-center mt-3"><a class="small" href="<?= e(url('/forgot-password')) ?>">Forgot your password?</a></div>
