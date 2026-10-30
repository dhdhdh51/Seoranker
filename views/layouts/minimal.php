<?php use Flatmates\Http\View; ?><!doctype html>
<html lang="en" data-bs-theme="light">
<head><?= View::partial('partials/head', get_defined_vars() + ['noindex' => true]) ?></head>
<body class="auth-body"><main class="minimal-wrap" id="main"><div style="max-width:30rem">
  <a class="brand justify-content-center w-100 mb-4" href="<?= e(url('/')) ?>"><?= View::partial('partials/brand_mark') ?><span class="brand-name"><?= e(brand()) ?></span></a>
  <?= $content ?>
</div></main><script src="<?= e(asset('js/app.js')) ?>" defer></script></body>
</html>
