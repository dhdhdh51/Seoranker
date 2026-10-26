<?php foreach ($conversations as $cv): $isCur = isset($currentId) && (int)$cv['id'] === $currentId; ?>
  <a class="conv<?= $cv['unread'] ? ' conv-unread' : '' ?>" href="<?= e(url('/chat/' . (int)$cv['id'])) ?>"<?= $isCur ? ' aria-current="page"' : '' ?>>
    <?= avatar($cv['other_photo'], $cv['other_name'], 'md') ?>
    <div class="flex-grow-1 min-w-0">
      <div class="d-flex justify-content-between gap-2"><span class="conv-name text-truncate"><?= e($cv['other_name']) ?></span><small class="text-body-secondary text-nowrap"><?= e(ago($cv['last_message_at'] ?? $cv['created_at'])) ?></small></div>
      <?php if ($cv['type'] === 'listing'): ?><div class="small text-primary text-truncate"><i class="bi bi-house" aria-hidden="true"></i> <?= e($cv['listing_title']) ?></div><?php endif; ?>
      <div class="d-flex justify-content-between gap-2 align-items-center"><span class="conv-snippet"><?= e($cv['last_body'] ?? 'Say hi 👋') ?></span><?php if ($cv['unread']): ?><span class="unread-dot" aria-label="<?= (int)$cv['unread'] ?> unread"><?= (int)$cv['unread'] ?></span><?php endif; ?></div>
    </div>
  </a>
<?php endforeach; ?>
