<div class="empty-state">
  <div class="empty-ico" aria-hidden="true"><i class="bi bi-<?= e($icon ?? 'inbox') ?>"></i></div>
  <h2><?= e($title) ?></h2>
  <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
  <?php if (!empty($cta)): ?><a class="btn btn-primary" href="<?= e(url($cta[1])) ?>"><?= e($cta[0]) ?></a><?php endif; ?>
</div>
