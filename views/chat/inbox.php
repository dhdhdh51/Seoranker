<h1 class="h4 mb-3">Messages</h1>
<?php if (!$conversations): ?>
  <div class="text-center py-5 text-body-secondary"><i class="bi bi-chat-dots display-5"></i><p class="mt-2">No conversations yet. Match with someone, or send an enquiry on a room.</p></div>
<?php else: ?>
<div class="list-group shadow-sm">
  <?php foreach ($conversations as $c): ?>
  <a class="list-group-item list-group-item-action d-flex gap-3 align-items-center py-3" href="<?= e(url('/chat/' . (int)$c['id'])) ?>">
    <?= avatar($c['other_photo'], $c['other_name'], 'md') ?>
    <div class="flex-grow-1 min-w-0">
      <div class="d-flex justify-content-between"><strong class="text-truncate"><?= e($c['other_name']) ?></strong><small class="text-body-secondary text-nowrap ms-2"><?= e(ago($c['last_message_at'] ?? $c['created_at'])) ?></small></div>
      <?php if ($c['type'] === 'listing'): ?><div class="small text-primary text-truncate"><i class="bi bi-house"></i> <?= e($c['listing_title']) ?></div><?php endif; ?>
      <div class="small text-body-secondary text-truncate<?= $c['unread'] ? ' fw-semibold text-body' : '' ?>"><?= e($c['last_body'] ?? 'Say hi 👋') ?></div>
    </div>
    <?php if ($c['unread']): ?><span class="badge rounded-pill bg-danger"><?= (int)$c['unread'] ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
