<h1 class="h4 mb-3">Saved</h1>
<h2 class="h6 text-body-secondary">Rooms</h2>
<?php if (!$saved['listings']): ?><p class="small text-body-secondary">Tap <i class="bi bi-bookmark"></i> on a listing to save it.</p><?php else: ?>
<div class="row g-3 mb-4"><?php foreach ($saved['listings'] as $l): ?><div class="col-sm-6 col-lg-4"><?= \Flatmates\Http\View::partial('partials/listing_card', ['l' => $l + ['gender_pref' => 'any']]) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<h2 class="h6 text-body-secondary mt-4">Students</h2>
<?php if (!$saved['profiles']): ?><p class="small text-body-secondary">Save interesting profiles to look at later.</p><?php else: ?>
<div class="list-group shadow-sm"><?php foreach ($saved['profiles'] as $p): ?>
  <a class="list-group-item list-group-item-action d-flex align-items-center gap-3" href="<?= e(url('/profile/' . (int)$p['user_id'])) ?>"><?= avatar($p['photo_path'], $p['display_name'], 'sm') ?><div><strong><?= e($p['display_name']) ?></strong><div class="small text-body-secondary"><?= e($p['college_name'] ?? '') ?><?= $p['budget_max'] ? ' · up to ' . e(inr($p['budget_max'])) : '' ?></div></div></a>
<?php endforeach; ?></div>
<?php endif; ?>
