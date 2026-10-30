<?php use Flatmates\Http\View; ?><!doctype html>
<html lang="en" data-bs-theme="light">
<head><?= View::partial('partials/head', get_defined_vars() + ['noindex' => true]) ?></head>
<body class="auth-body<?= View::shared('inApp', false) ? ' in-app' : '' ?>">
<div class="auth-split">
  <aside class="auth-aside" aria-hidden="true">
    <picture><source type="image/webp" srcset="<?= e(asset('img/photos/student-room-960.webp')) ?>"><img src="<?= e(asset('img/photos/student-room-960.jpg')) ?>" alt="" width="960" height="540"></picture>
    <h2>Find Your People.<br>Find Your Place.</h2>
    <p>Compatible student flatmates, rooms near campus, and private chat — without sharing your number.</p>
    <ul class="list-unstyled small d-grid gap-2 mt-2 mb-0" style="color:rgba(255,255,255,.85)">
      <li><i class="bi bi-check-circle me-2"></i>Free for students, no brokerage</li>
      <li><i class="bi bi-check-circle me-2"></i>Badges that explain exactly what was checked</li>
      <li><i class="bi bi-check-circle me-2"></i>Report and block on every profile and message</li>
    </ul>
  </aside>
  <main class="auth-main" id="main">
    <div class="auth-wrap">
      <a class="brand justify-content-center w-100 mb-4" href="<?= e(url('/')) ?>"><?= View::partial('partials/brand_mark') ?><span class="brand-name"><?= e(brand()) ?></span></a>
      <div class="card auth-card shadow-sm"><div class="card-body p-4 p-sm-5">
        <?= View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
        <?= $content ?>
      </div></div>
      <p class="text-center small text-body-secondary mt-4 mb-0">Operated by <?= e(setting('brand.company', 'NoidaRankers')) ?> · <a href="<?= e(url('/safety')) ?>">Safety</a> · <a href="<?= e(url('/privacy')) ?>">Privacy</a> · <a href="<?= e(url('/terms')) ?>">Terms</a></p>
    </div>
  </main>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
