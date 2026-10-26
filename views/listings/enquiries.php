<h1 class="h4 mb-3">Enquiries</h1>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link<?= $tab === 'received' ? ' active' : '' ?>" href="<?= e(url('/enquiries')) ?>">Received (<?= count(array_filter($received, static fn($e) => $e['status'] === 'new')) ?> new)</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'sent' ? ' active' : '' ?>" href="<?= e(url('/enquiries', ['tab' => 'sent'])) ?>">Sent</a></li>
</ul>
<?php $rows = $tab === 'sent' ? $sent : $received; if (!$rows): ?><p class="text-body-secondary">Nothing here yet.</p><?php endif; ?>
<div class="vstack gap-3">
<?php foreach ($rows as $e): ?>
  <div class="card shadow-sm border-0"><div class="card-body">
    <div class="d-flex justify-content-between gap-2 flex-wrap">
      <div>
        <?php if ($tab === 'received'): ?><div class="d-flex align-items-center gap-2"><?= avatar($e['student_photo'], (string)$e['student_name'], 'sm') ?><a href="<?= e(url('/profile/' . (int)$e['student_id'])) ?>" class="fw-semibold"><?= e($e['student_name']) ?></a><?= $e['student_verified'] ? '<i class="bi bi-patch-check-fill text-primary" title="Verified student"></i>' : '' ?></div><?php endif; ?>
        <div class="small text-body-secondary mt-1"><?= $e['kind'] === 'viewing' ? '<i class="bi bi-calendar-check"></i> Viewing request · ' . e(fmt_dt($e['viewing_at'])) : '<i class="bi bi-envelope"></i> Enquiry' ?> · <a href="<?= e(url('/rooms/' . $e['slug'])) ?>"><?= e($e['title']) ?></a> · <?= e(ago($e['created_at'])) ?></div>
      </div>
      <span class="badge align-self-start <?= $e['status'] === 'accepted' ? 'text-bg-success' : ($e['status'] === 'new' ? 'text-bg-primary' : 'text-bg-secondary') ?>"><?= e(ucfirst($e['status'])) ?></span>
    </div>
    <p class="mt-2 mb-2 text-pre"><?= e($e['message']) ?></p>
    <div class="d-flex gap-2">
      <?php if ($tab === 'received' && $e['status'] === 'new'): ?>
        <form method="post" action="<?= e(url('/enquiries/' . (int)$e['id'] . '/respond')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="accept"><button class="btn btn-sm btn-success">Accept & chat</button></form>
        <form method="post" action="<?= e(url('/enquiries/' . (int)$e['id'] . '/respond')) ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="decline"><button class="btn btn-sm btn-outline-secondary">Decline</button></form>
      <?php elseif ($e['status'] === 'accepted' && $e['conversation_id']): ?>
        <a class="btn btn-sm btn-primary" href="<?= e(url('/chat/' . (int)$e['conversation_id'])) ?>">Open chat</a>
      <?php elseif ($tab === 'sent' && $e['status'] === 'new'): ?>
        <form method="post" action="<?= e(url('/enquiries/' . (int)$e['id'] . '/cancel')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Cancel</button></form>
      <?php endif; ?>
    </div>
  </div></div>
<?php endforeach; ?>
</div>
