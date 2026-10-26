<?php if (!empty($flash)): ?><div class="flash-wrap" aria-live="polite"><?php foreach ($flash as $type => $msgs): foreach ((array)$msgs as $m): $t = in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info'; ?>
<div class="alert alert-<?= e($t) ?> alert-dismissible fade show d-flex gap-2 align-items-start" role="<?= $t === 'danger' ? 'alert' : 'status' ?>">
  <i class="bi bi-<?= ['success' => 'check-circle-fill', 'danger' => 'exclamation-octagon-fill', 'warning' => 'exclamation-triangle-fill', 'info' => 'info-circle-fill'][$t] ?>" aria-hidden="true"></i><div><?= e($m) ?></div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endforeach; endforeach; ?></div><?php endif; ?>
