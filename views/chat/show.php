<?php use Flatmates\Http\View;
View::share('scripts', ['chat.js']); $last = $messages ? end($messages)['id'] : 0; ?>
<div class="chat-shell">
  <nav class="chat-list" aria-label="Conversations"><?= View::partial('chat/_list', ['conversations' => $conversations, 'currentId' => (int)$c['id']]) ?></nav>
  <section class="chat" id="chat" data-url="<?= e(url('/api/chat/' . (int)$c['id'])) ?>" data-last="<?= (int)$last ?>" data-first="<?= (int)($messages[0]['id'] ?? 0) ?>" aria-label="Conversation with <?= e($c['other_name']) ?>">
    <header class="chat-head">
      <a class="nav-icon d-lg-none" href="<?= e(url('/chat')) ?>" aria-label="Back to messages"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
      <a class="d-flex align-items-center gap-2 text-reset text-decoration-none flex-grow-1 min-w-0" href="<?= e(url('/profile/' . (int)$c['other_id'])) ?>">
        <?= avatar($c['other_photo'], $c['other_name'], 'md') ?>
        <div class="min-w-0"><strong class="d-block text-truncate"><?= e($c['other_name']) ?></strong>
          <?php if ($c['type'] === 'listing' && $c['listing_slug']): ?><span class="small text-body-secondary text-truncate d-block"><i class="bi bi-house" aria-hidden="true"></i> About: <?= e($c['listing_title']) ?></span>
          <?php else: ?><span class="small text-body-secondary d-block">Your match · view profile</span><?php endif; ?>
        </div>
      </a>
      <div class="d-none d-md-block"><?= View::partial('partials/badges', ['keys' => array_values(array_intersect($c['other_badges'], ['college_email', 'student_id']))]) ?></div>
      <div class="dropdown"><button class="nav-icon" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Conversation options"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= e(url('/profile/' . (int)$c['other_id'])) ?>"><i class="bi bi-person me-2" aria-hidden="true"></i>View profile</a></li>
          <?php if ($c['type'] === 'listing' && $c['listing_slug']): ?><li><a class="dropdown-item" href="<?= e(url('/rooms/' . $c['listing_slug'])) ?>"><i class="bi bi-house me-2" aria-hidden="true"></i>View listing</a></li><?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item text-danger" type="button" data-report="user" data-id="<?= (int)$c['other_id'] ?>"><i class="bi bi-flag me-2" aria-hidden="true"></i>Report <?= e($c['other_name']) ?></button></li>
          <li><form method="post" action="<?= e(url('/block/' . (int)$c['other_id'])) ?>" data-confirm="Block <?= e($c['other_name']) ?>? You won't see or hear from each other again, and this chat will close." data-confirm-ok="Block"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-slash-circle me-2" aria-hidden="true"></i>Block</button></form></li>
          <?php if ($c['type'] === 'match' && $c['match_id']): ?><li><form method="post" action="<?= e(url('/matches/' . (int)$c['match_id'] . '/unmatch')) ?>" data-confirm="Unmatch <?= e($c['other_name']) ?>? You won't be able to message each other any more." data-confirm-ok="Unmatch"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-heartbreak me-2" aria-hidden="true"></i>Unmatch</button></form></li><?php endif; ?>
        </ul></div>
    </header>
    <div class="chat-safety"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Never pay a deposit before visiting and seeing the terms in writing. <a href="<?= e(url('/safety')) ?>#scams">Safety tips</a></div>
    <div class="chat-log" id="chatLog" role="log" aria-live="polite" aria-label="Messages">
      <?php if (count($messages) >= \Flatmates\Services\ChatService::PAGE): ?><button class="btn btn-sm btn-light d-block mx-auto mb-2" type="button" data-older>Load earlier messages</button><?php endif; ?>
      <?php foreach ($messages as $m): ?>
        <div class="msg<?= $m['mine'] ? ' mine' : '' ?>" data-id="<?= (int)$m['id'] ?>">
          <div class="bubble<?= $m['hidden'] ? ' removed' : '' ?>"><?= $m['hidden'] ? 'Message removed by a moderator' : nl2br(e($m['body'])) ?></div>
          <div class="meta"><time><?= e($m['at']) ?></time><?= $m['mine'] && $m['read'] ? ' · Seen' : '' ?><?php if (!$m['mine'] && !$m['hidden']): ?> · <button type="button" class="btn btn-link btn-sm p-0 align-baseline small" data-report="message" data-id="<?= (int)$m['id'] ?>">Report</button><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$messages): ?><div class="chat-empty m-auto text-center text-body-secondary small" style="max-width:20rem"><div class="empty-ico mx-auto mb-2" style="width:56px;height:56px;display:grid;place-items:center;border-radius:50%;background:var(--fm-blue-50);color:var(--brand);font-size:1.4rem" aria-hidden="true"><i class="bi bi-chat-heart"></i></div>Say hello 👋 — introduce yourself and suggest a quick video call before meeting.</div><?php endif; ?>
    </div>
    <?php if ($c['can_send']): ?>
    <form class="chat-form" id="chatForm" autocomplete="off">
      <label class="visually-hidden" for="chatInput">Message <?= e($c['other_name']) ?></label>
      <textarea class="form-control" id="chatInput" name="body" rows="1" maxlength="2000" placeholder="Write a message…" required></textarea>
      <button class="btn btn-primary" aria-label="Send message"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
    </form>
    <?php else: ?>
    <div class="chat-closed"><i class="bi bi-lock me-1" aria-hidden="true"></i><?= e($c['send_problem']) ?><?php if (str_contains((string)$c['send_problem'], 'Verify')): ?> <a href="<?= e(url('/verify')) ?>">Verify now</a><?php endif; ?></div>
    <?php endif; ?>
  </section>
</div>
