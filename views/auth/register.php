<h1 class="h4 mb-1">Create your free account</h1>
<p class="text-body-secondary small mb-4">For college students aged <?= (int)$minAge ?>+. Your email and date of birth are never shown to other students.</p>
<form method="post" action="<?= e(url('/register')) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="d-none" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
  <div class="mb-3"><label class="form-label" for="name">Full name</label>
    <input class="form-control<?= invalid($errors, 'name') ?>" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="120" autocomplete="name"><?= field_error($errors, 'name') ?></div>
  <div class="mb-3"><label class="form-label" for="email">Email</label>
    <input class="form-control<?= invalid($errors, 'email') ?>" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email" inputmode="email"><?= field_error($errors, 'email') ?>
    <div class="form-text">You can verify your college email later for a "Verified student" badge.</div></div>
  <div class="mb-3"><label class="form-label" for="dob">Date of birth</label>
    <input class="form-control<?= invalid($errors, 'date_of_birth') ?>" id="dob" type="date" name="date_of_birth" value="<?= e(old('date_of_birth')) ?>" required max="<?= e(date('Y-m-d', strtotime('-' . $minAge . ' years'))) ?>"><?= field_error($errors, 'date_of_birth') ?></div>
  <div class="row g-2 mb-3">
    <div class="col-sm-6"><label class="form-label" for="password">Password</label>
      <input class="form-control<?= invalid($errors, 'password') ?>" id="password" type="password" name="password" required minlength="8" autocomplete="new-password"><?= field_error($errors, 'password') ?></div>
    <div class="col-sm-6"><label class="form-label" for="password2">Confirm</label>
      <input class="form-control<?= invalid($errors, 'password_confirmation') ?>" id="password2" type="password" name="password_confirmation" required autocomplete="new-password"><?= field_error($errors, 'password_confirmation') ?></div>
    <div class="form-text">At least 8 characters with a number or symbol.</div>
  </div>
  <div class="form-check mb-2"><input class="form-check-input<?= invalid($errors, 'terms') ?>" type="checkbox" name="age_confirmed" value="1" id="age"<?= checked(old('age_confirmed')) ?>><label class="form-check-label small" for="age">I confirm I am at least <?= (int)$minAge ?> years old and a student.</label></div>
  <div class="form-check mb-3"><input class="form-check-input<?= invalid($errors, 'terms') ?>" type="checkbox" name="terms" value="1" id="terms"<?= checked(old('terms')) ?>><label class="form-check-label small" for="terms">I agree to the <a href="<?= e(url('/terms')) ?>" target="_blank">Terms</a>, <a href="<?= e(url('/privacy')) ?>" target="_blank">Privacy policy</a> and <a href="<?= e(url('/safety')) ?>" target="_blank">Safety guidelines</a>.</label><?= field_error($errors, 'terms') ?></div>
  <button class="btn btn-primary w-100 btn-lg">Create account</button>
</form>
<p class="text-center small mt-3 mb-0">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
