<!doctype html>
<html lang="en" data-bs-theme="light">
<head><?= \Flatmates\Http\View::partial('partials/head', get_defined_vars() + ['noindex' => true]) ?></head>
<body class="auth-body">
<main class="auth-wrap">
  <a class="d-flex align-items-center justify-content-center gap-2 fw-bold text-decoration-none mb-4 fs-5 text-body" href="<?= e(url('/')) ?>"><span class="brand-mark"><?= e(setting('brand.logo_text', 'SF')) ?></span><?= e(brand()) ?></a>
  <div class="card shadow-sm border-0 auth-card"><div class="card-body p-4">
    <?= \Flatmates\Http\View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
    <?= $content ?>
  </div></div>
  <p class="text-center small text-body-secondary mt-4">Operated by <?= e(setting('brand.company', 'NoidaRankers')) ?> · <a href="<?= e(url('/safety')) ?>">Safety</a> · <a href="<?= e(url('/privacy')) ?>">Privacy</a></p>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
