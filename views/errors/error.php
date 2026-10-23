<?php $code = (int)($status_code ?? 500); ?>
<div class="card shadow-lg border-0 auth-card">
  <div class="card-body p-5 text-center">
    <div class="display-5 fw-bold text-primary"><?= $code ?></div>
    <h1 class="h4 mt-2"><?= e($title ?? 'Error') ?></h1>
    <p class="text-body-secondary"><?= e($message ?? '') ?></p>
    <a class="btn btn-primary" href="<?= e(function_exists('url') ? url('/') : '/') ?>">Back to dashboard</a>
  </div>
</div>
