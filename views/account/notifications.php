<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">Notifications</h1>
<?php if ($rows): ?><form method="post" action="<?= e(url('/notifications/read')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-light">Mark all read</button></form><?php endif; ?></div>
<?php if (!$rows): ?><p class="text-body-secondary">You're all caught up.</p><?php endif; ?>
<div class="list-group shadow-sm"><?php foreach ($rows as $n): ?>
  <<?= $n['link'] ? 'a href="' . e(url($n['link'])) . '"' : 'div' ?> class="list-group-item<?= $n['link'] ? ' list-group-item-action' : '' ?> d-flex justify-content-between gap-2<?= $n['is_read'] ? '' : ' fw-semibold' ?>">
    <span><?= $n['is_read'] ? '' : '<i class="bi bi-dot text-primary"></i>' ?><?= e($n['title']) ?></span><small class="text-body-secondary text-nowrap"><?= e(ago($n['created_at'])) ?></small>
  </<?= $n['link'] ? 'a' : 'div' ?>>
<?php endforeach; ?></div>
