<div class="page-head">
  <h1 class="h3 mb-0">Notifications</h1>
  <?php if ($rows): ?><form method="post" action="<?= e(url('/notifications/read-all')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">Mark all as read</button></form><?php endif; ?>
</div>
<div class="card card-soft">
  <?php if (!$rows): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'bell', 'heading' => 'You are all caught up']) ?><?php else: ?>
  <ul class="list-group list-group-flush">
    <?php foreach ($rows as $n): ?>
      <li class="list-group-item d-flex justify-content-between align-items-start gap-2<?= $n['is_read'] ? '' : ' notification-unread' ?>">
        <div class="min-w-0"><div class="fw-semibold"><?= e($n['title']) ?></div><?php if ($n['body']): ?><div class="small text-body-secondary text-break"><?= e($n['body']) ?></div><?php endif; ?><div class="small text-body-secondary"><?= e(fmt_dt($n['created_at'])) ?></div></div>
        <?php if ($n['link']): ?><form method="post" action="<?= e(url('/notifications/' . $n['id'] . '/read')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Open</button></form><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul><?php endif; ?>
</div>
