<h1 class="h4 mb-3">Choose a new password</h1>
<form method="post" action="<?= e(url('/reset-password')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="mb-3">
    <label class="form-label" for="password">New password</label>
    <input class="form-control" type="password" id="password" name="password" required minlength="<?= (int)\BharatSeo\Security\Auth::MIN_PASSWORD_LENGTH ?>" maxlength="200" autocomplete="new-password">
    <div class="form-text">At least <?= (int)\BharatSeo\Security\Auth::MIN_PASSWORD_LENGTH ?> characters with letters and a number or symbol.</div>
  </div>
  <div class="mb-3">
    <label class="form-label" for="password_confirmation">Confirm password</label>
    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required maxlength="200" autocomplete="new-password">
  </div>
  <button class="btn btn-primary w-100" type="submit">Update password</button>
</form>
