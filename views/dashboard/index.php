<?php
use Flatmates\Http\View;
use Flatmates\Services\TrustService;

$first = (string)strtok((string)($p['display_name'] ?? ''), ' ');
?>
<div class="page-head">
  <div><h1>Hi <?= e($first) ?> 👋</h1><p>Here's what's happening with your flat hunt.</p></div>
  <div class="d-flex gap-2"><a class="btn btn-primary" href="<?= e(url('/discover')) ?>"><i class="bi bi-stars" aria-hidden="true"></i> Discover flatmates</a><a class="btn btn-light d-none d-sm-inline-flex" href="<?= e(url('/rooms', ['city_id' => $p['city_id']])) ?>">Browse rooms</a></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><a class="stat" href="<?= e(url('/matches')) ?>"><div class="stat-label"><i class="bi bi-heart" aria-hidden="true"></i>Matches</div><div class="stat-value"><?= (int)$matchCount ?></div><?php if ($pendingLikes): ?><div class="small text-body-secondary"><?= (int)$pendingLikes ?> liked you</div><?php endif; ?></a></div>
  <div class="col-6 col-lg-3"><a class="stat" href="<?= e(url('/chat')) ?>"><div class="stat-label"><i class="bi bi-chat-dots" aria-hidden="true"></i>Unread messages</div><div class="stat-value"><?= (int)$unread ?></div></a></div>
  <div class="col-6 col-lg-3"><a class="stat" href="<?= e(url('/enquiries')) ?>"><div class="stat-label"><i class="bi bi-envelope" aria-hidden="true"></i>New enquiries</div><div class="stat-value"><?= (int)$newEnquiries ?></div></a></div>
  <div class="col-6 col-lg-3"><a class="stat" href="<?= e(url('/saved')) ?>"><div class="stat-label"><i class="bi bi-bookmark" aria-hidden="true"></i>Saved</div><div class="stat-value"><?= (int)$saved ?></div></a></div>
</div>

<div class="row g-4">
  <div class="col-lg-8 d-grid gap-4 align-content-start">
    <?php if ($steps): ?>
    <section class="surface p-4" aria-labelledby="nextTitle">
      <h2 class="h5 mb-3" id="nextTitle">Next steps</h2>
      <div class="d-grid gap-2">
        <?php foreach ($steps as $s): ?>
          <div class="d-flex gap-3 align-items-center p-3 rounded-3 bg-soft">
            <span class="trust-ico pending flex-none"><i class="bi bi-<?= e($s['icon']) ?>" aria-hidden="true"></i></span>
            <div class="flex-grow-1 min-w-0"><div class="fw-600"><?= e($s['title']) ?></div><div class="small text-body-secondary"><?= e($s['text']) ?></div></div>
            <a class="btn btn-sm btn-light flex-none" href="<?= e(url($s['href'])) ?>"><?= e($s['cta']) ?></a>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section aria-labelledby="mTitle">
      <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0" id="mTitle">Your matches</h2><a class="small" href="<?= e(url('/matches')) ?>">See all</a></div>
      <?php if (!$matches): ?>
        <div class="surface"><?= View::partial('partials/empty', ['icon' => 'heart', 'title' => 'No matches yet', 'text' => 'When you and another student both like each other, they appear here.', 'cta' => ['Start discovering', '/discover']]) ?></div>
      <?php else: ?>
        <div class="row g-3"><?php foreach ($matches as $m): ?>
          <div class="col-6 col-md-4"><a class="surface p-3 d-flex gap-2 align-items-center text-decoration-none text-body h-100" href="<?= e(url('/chat/' . (int)$m['conversation_id'])) ?>">
            <?= avatar($m['photo_path'], $m['display_name'], 'md') ?>
            <div class="min-w-0"><div class="fw-600 text-truncate"><?= e($m['display_name']) ?></div><div class="small text-body-secondary text-truncate"><?= $m['score'] !== null ? (int)$m['score'] . '% match' : '' ?><?= $m['unread'] ? ' · <span class="text-primary fw-600">' . (int)$m['unread'] . ' new</span>' : '' ?></div></div>
          </a></div>
        <?php endforeach; ?></div>
      <?php endif; ?>
    </section>

    <section aria-labelledby="rTitle">
      <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0" id="rTitle">Rooms in <?= e($p['city_name'] ?? 'your city') ?></h2><a class="small" href="<?= e(url('/rooms', ['city_id' => $p['city_id']])) ?>">See all</a></div>
      <?php if (!$rooms): ?>
        <div class="surface"><?= View::partial('partials/empty', ['icon' => 'house', 'title' => 'No rooms listed here yet', 'text' => 'Have a vacant bed? Listing it is free.', 'cta' => ['Post a room', '/my/listings/new']]) ?></div>
      <?php else: ?>
        <div class="row g-3"><?php foreach ($rooms as $l): ?><div class="col-md-4"><?= View::partial('partials/listing_card', ['l' => $l]) ?></div><?php endforeach; ?></div>
      <?php endif; ?>
    </section>
  </div>

  <aside class="col-lg-4 d-grid gap-4 align-content-start">
    <section class="surface p-4" aria-labelledby="tTitle">
      <div class="d-flex align-items-center gap-3 mb-3"><?= avatar($p['photo_path'], (string)$p['display_name'], 'lg') ?><div><h2 class="h6 mb-0" id="tTitle"><?= e($p['display_name']) ?></h2><a class="small" href="<?= e(url('/profile')) ?>">View my profile</a></div></div>
      <div class="d-flex justify-content-between small mb-1"><span>Profile completeness</span><strong><?= (int)$p['completeness'] ?>%</strong></div>
      <div class="progress mb-3" role="progressbar" aria-label="Profile completeness" aria-valuenow="<?= (int)$p['completeness'] ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px"><div class="progress-bar" style="width:<?= (int)$p['completeness'] ?>%"></div></div>
      <div class="small fw-600 mb-2">Your trust badges</div>
      <?php foreach (['email_verified', 'college_email', 'student_id'] as $k): $d = TrustService::BADGES[$k]; $has = in_array($k, $badges, true); $pending = $k === 'student_id' && $pendingDoc; ?>
        <div class="d-flex align-items-center gap-2 py-1 small">
          <i class="bi bi-<?= $has ? 'check-circle-fill text-success' : ($pending ? 'hourglass-split' : 'circle') ?>" style="<?= $pending ? 'color:var(--fm-pending)' : (!$has ? 'color:var(--fm-text-subtle)' : '') ?>" aria-hidden="true"></i>
          <span class="flex-grow-1"><?= e($d['label']) ?></span>
          <span class="<?= $has ? 'text-success' : 'text-body-secondary' ?>"><?= $has ? 'Done' : ($pending ? 'In review' : 'Not yet') ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!TrustService::isStudentVerified($badges)): ?><a class="btn btn-sm btn-outline-primary w-100 mt-3" href="<?= e(url('/verify')) ?>">Open verification centre</a><?php endif; ?>
    </section>

    <section class="surface p-4" aria-labelledby="lTitle">
      <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0" id="lTitle">My listings</h2><a class="small" href="<?= e(url('/my/listings/new')) ?>">+ New</a></div>
      <?php if (!$listings): ?><p class="small text-body-secondary mb-0">You haven't listed a room. <a href="<?= e(url('/my/listings/new')) ?>">List a vacant bed</a></p>
      <?php else: foreach ($listings as $l): ?>
        <a class="d-flex gap-2 align-items-center py-2 text-decoration-none text-body" href="<?= e(url('/my/listings/' . (int)$l['id'] . '/edit')) ?>"><div class="thumb-sm" style="width:52px;height:40px"><?php if ($l['cover']): ?><img src="<?= e(media_url($l['cover'], 'sm')) ?>" alt="" loading="lazy"><?php else: ?><i class="bi bi-image" aria-hidden="true"></i><?php endif; ?></div>
          <div class="min-w-0 flex-grow-1"><div class="small fw-600 text-truncate"><?= e($l['title']) ?></div><div class="small text-body-secondary"><?= e(label('listing_status', $l['status'])) ?></div></div></a>
      <?php endforeach; if ($listingCount > 3): ?><a class="small" href="<?= e(url('/my/listings')) ?>">All <?= (int)$listingCount ?> listings</a><?php endif; endif; ?>
    </section>

    <section class="surface p-4" aria-labelledby="nTitle">
      <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0" id="nTitle">Recent notifications</h2><a class="small" href="<?= e(url('/notifications')) ?>">All</a></div>
      <?php if (!$notifications): ?><p class="small text-body-secondary mb-0">You're all caught up.</p><?php else: foreach ($notifications as $n): ?>
        <div class="small py-2 border-bottom<?= $n['is_read'] ? ' text-body-secondary' : ' fw-600' ?>"><?= $n['link'] ? '<a class="text-reset" href="' . e(url($n['link'])) . '">' . e($n['title']) . '</a>' : e($n['title']) ?><div class="fw-normal text-body-secondary" style="font-size:.75rem"><?= e(ago($n['created_at'])) ?></div></div>
      <?php endforeach; endif; ?>
    </section>
  </aside>
</div>
