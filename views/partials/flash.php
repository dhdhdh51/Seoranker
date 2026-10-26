<?php foreach (($flash ?? []) as $type => $msgs): foreach ((array)$msgs as $m): ?>
<div class="alert alert-<?= e(in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info') ?> alert-dismissible fade show" role="alert">
  <?= e($m) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endforeach; endforeach; ?>
