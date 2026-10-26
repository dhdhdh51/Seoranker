<?php \Flatmates\Http\View::share('scripts', ['chat.js']); $last = $messages ? end($messages)['id'] : 0; ?>
<div class="chat card shadow-sm border-0" id="chat" data-url="<?= e(url('/api/chat/' . (int)$c['id'])) ?>" data-last="<?= (int)$last ?>" data-first="<?= (int)($messages[0]['id'] ?? 0) ?>">
  <div class="chat-head d-flex align-items-center gap-2 p-2 border-bottom">
    <a class="btn btn-link" href="<?= e(url('/chat')) ?>" aria-label="Back to messages"><i class="bi bi-arrow-left"></i></a>
    <a class="d-flex align-items-center gap-2 text-reset text-decoration-none flex-grow-1 min-w-0" href="<?= e(url('/profile/' . (int)$c['other_id'])) ?>">
      <?= avatar($c['other_photo'], $c['other_name'], 'sm') ?>
      <div class="min-w-0"><strong class="d-block text-truncate"><?= e($c['other_name']) ?></strong>
        <?php if ($c['type'] === 'listing' && $c['listing_slug']): ?><span class="small text-body-secondary text-truncate d-block"><i class="bi bi-house"></i> <?= e($c['listing_title']) ?></span><?php endif; ?></div>
    </a>
    <div class="dropdown"><button class="btn btn-link" data-bs-toggle="dropdown" aria-label="Conversation options"><i class="bi bi-three-dots-vertical"></i></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if ($c['type'] === 'listing' && $c['listing_slug']): ?><li><a class="dropdown-item" href="<?= e(url('/rooms/' . $c['listing_slug'])) ?>">View listing</a></li><?php endif; ?>
        <li><button class="dropdown-item text-danger" type="button" data-report="user" data-id="<?= (int)$c['other_id'] ?>"><i class="bi bi-flag me-2"></i>Report</button></li>
        <li><form method="post" action="<?= e(url('/block/' . (int)$c['other_id'])) ?>" data-confirm="Block this person? The conversation will close."><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-slash-circle me-2"></i>Block</button></form></li>
        <?php if ($c['type'] === 'match' && $c['match_id']): ?><li><form method="post" action="<?= e(url('/matches/' . (int)$c['match_id'] . '/unmatch')) ?>" data-confirm="Unmatch? You won't be able to message each other."><?= csrf_field() ?><button class="dropdown-item">Unmatch</button></form></li><?php endif; ?>
      </ul></div>
  </div>
  <div class="chat-safety small px-3 py-2"><i class="bi bi-shield-check me-1"></i>Never pay a deposit before visiting. Meet in public first. <a href="<?= e(url('/safety')) ?>">Safety tips</a></div>
  <div class="chat-log p-3" id="chatLog" role="log" aria-live="polite">
    <?php if (count($messages) >= \Flatmates\Services\ChatService::PAGE): ?><button class="btn btn-sm btn-light d-block mx-auto mb-2" type="button" data-older>Load earlier messages</button><?php endif; ?>
    <?php foreach ($messages as $m): ?>
      <div class="msg<?= $m['mine'] ? ' mine' : '' ?>" data-id="<?= (int)$m['id'] ?>">
        <div class="bubble<?= $m['hidden'] ? ' removed' : '' ?>"><?= $m['hidden'] ? '<em>Message removed by a moderator</em>' : nl2br(e($m['body'])) ?></div>
        <div class="meta"><?= e($m['at']) ?><?php if (!$m['mine'] && !$m['hidden']): ?> · <button type="button" class="btn btn-link btn-sm p-0 align-baseline small" data-report="message" data-id="<?= (int)$m['id'] ?>">Report</button><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$messages): ?><p class="text-center text-body-secondary small chat-empty">Say hello 👋 — introduce yourself and suggest a quick call.</p><?php endif; ?>
  </div>
  <?php if ($c['can_send']): ?>
  <form class="chat-form d-flex gap-2 p-2 border-top" id="chatForm" autocomplete="off">
    <label class="visually-hidden" for="chatInput">Message</label>
    <textarea class="form-control" id="chatInput" name="body" rows="1" maxlength="2000" placeholder="Write a message…" required></textarea>
    <button class="btn btn-primary" aria-label="Send"><i class="bi bi-send-fill"></i></button>
  </form>
  <?php else: ?>
  <div class="p-3 border-top text-center small text-body-secondary"><?= e($c['send_problem']) ?><?php if (str_contains((string)$c['send_problem'], 'Verify')): ?> <a href="<?= e(url('/verify')) ?>">Verify now</a><?php endif; ?></div>
  <?php endif; ?>
</div>
