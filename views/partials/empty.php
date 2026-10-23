<div class="empty-state text-center py-5">
  <div class="empty-icon"><i class="bi bi-<?= e($icon ?? 'inbox') ?>"></i></div>
  <h3 class="h6 mt-3 mb-1"><?= e($heading ?? 'Nothing here yet') ?></h3>
  <?php if (!empty($text)): ?><p class="text-body-secondary small mb-3"><?= e($text) ?></p><?php endif; ?>
  <?php if (!empty($actionUrl)): ?><a class="btn btn-primary btn-sm" href="<?= e($actionUrl) ?>"><?= e($actionLabel ?? 'Get started') ?></a><?php endif; ?>
</div>
