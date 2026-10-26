<div class="page-head">
  <div><h1>Matches</h1><p>Students you both liked. Say hi and plan a video call first.</p></div>
  <?php if ($pendingLikes): ?><span class="small text-body-secondary"><i class="bi bi-heart-fill text-danger"></i> <?= (int)$pendingLikes ?> people liked you — keep swiping to find them</span><?php endif; ?>
</div>
<?php if (!$matches): ?>
  <div class="surface"><?= \Flatmates\Http\View::partial('partials/empty', ['icon' => 'heart', 'title' => 'No matches yet', 'text' => 'When you and another student both like each other, they show up here.', 'cta' => ['Start discovering', '/discover']]) ?></div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($matches as $m): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card match-card h-100 shadow-sm border-0 text-center p-3">
      <a href="<?= e(url('/profile/' . (int)$m['other_id'])) ?>" class="mx-auto"><?= avatar($m['photo_path'], $m['display_name'], 'xl') ?></a>
      <h2 class="h6 mt-2 mb-0"><?= e($m['display_name']) ?></h2>
      <div class="small text-body-secondary text-truncate"><?= e($m['college_name'] ?? '') ?></div>
      <?= \Flatmates\Http\View::partial('partials/badges', ['keys' => array_values(array_intersect($m['badges'] ?? [], ['college_email', 'student_id'])), 'class' => 'justify-content-center mt-1']) ?>
      <?php if ($m['score'] !== null): ?><div class="small mt-1"><span class="chip chip-ok"><?= (int)$m['score'] ?>% match</span></div><?php endif; ?>
      <a class="btn btn-sm btn-primary mt-2" href="<?= e(url('/chat/' . (int)$m['conversation_id'])) ?>"><i class="bi bi-chat-dots"></i> Chat<?= $m['unread'] ? ' <span class="badge bg-danger">' . (int)$m['unread'] . '</span>' : '' ?></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
