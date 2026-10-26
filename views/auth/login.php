<h1 class="h4 mb-4">Welcome back</h1>
<form method="post" action="<?= e(url('/login')) ?>">
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control" id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="username" autofocus></div>
  <div class="mb-2"><label class="form-label" for="password">Password</label><input class="form-control" id="password" type="password" name="password" required autocomplete="current-password"></div>
  <div class="text-end mb-3"><a class="small" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a></div>
  <button class="btn btn-primary w-100 btn-lg">Sign in</button>
</form>
<p class="text-center small mt-3 mb-0">New here? <a href="<?= e(url('/register')) ?>">Create a free account</a></p>
