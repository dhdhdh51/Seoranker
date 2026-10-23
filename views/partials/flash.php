<?php foreach (($flash ?? []) as $type => $messages): ?>
  <?php foreach ((array)$messages as $msg): ?>
    <div class="alert alert-<?= e(in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info') ?> alert-dismissible fade show" role="alert">
      <?= e($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>
