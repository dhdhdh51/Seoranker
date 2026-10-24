<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? brand()) ?> · <?= e(brand()) ?></title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-wrap">
  <div class="auth-card card shadow-lg border-0">
    <div class="card-body p-4 p-md-5">
      <div class="d-flex align-items-center gap-2 mb-4">
        <span class="brand-mark"><?= e(brand_initial()) ?></span>
        <div><div class="fw-bold fs-5"><?= e(brand()) ?></div><div class="small text-body-secondary">Backlink research, outreach &amp; monitoring</div></div>
      </div>
      <?= \BharatSeo\Http\View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
      <?= $content ?>
    </div>
  </div>
  <p class="text-center small text-body-secondary mt-3 mb-0">Responsible link building · no spam, no fake accounts, no guarantees.</p>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
