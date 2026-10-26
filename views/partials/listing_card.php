<?php /** @var array $l */
$amen = array_slice(array_values(array_filter(explode(',', (string)($l['amenities'] ?? '')))), 0, 3);
$showStatus = !empty($showStatus);
?>
<article class="listing-card">
  <div class="listing-media">
    <?php if (!empty($l['cover'])): ?>
      <img src="<?= e(media_url($l['cover'], 'sm')) ?>" alt="" loading="lazy" decoding="async" width="480" height="360">
    <?php else: ?><span class="thumb-empty" aria-hidden="true"><i class="bi bi-house-door"></i></span><?php endif; ?>
    <div class="media-tags">
      <span class="media-pill"><?= e(label('listing_type', $l['type'])) ?></span>
      <?php if ($showStatus && ($l['status'] ?? '') !== 'published'): ?><span class="media-pill"><?= e(label('listing_status', $l['status'])) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="listing-body">
    <div class="listing-price"><?= e(inr($l['rent'])) ?> <small>/ month</small></div>
    <h3 class="listing-title"><a href="<?= e(url('/rooms/' . $l['slug'])) ?>"><?= e($l['title']) ?></a></h3>
    <div class="listing-meta"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($l['locality']) ?>, <?= e($l['city_name']) ?></div>
    <div class="listing-facts">
      <span><i class="bi bi-calendar-event" aria-hidden="true"></i><?= e(!empty($l['available_from']) && strtotime($l['available_from']) > time() ? 'From ' . fmt_date($l['available_from']) : 'Available now') ?></span>
      <?php if (isset($l['deposit']) && $l['deposit'] !== null): ?><span><i class="bi bi-safe" aria-hidden="true"></i>Deposit <?= e(inr($l['deposit'])) ?></span><?php endif; ?>
      <?php if (($l['gender_pref'] ?? 'any') !== 'any'): ?><span><i class="bi bi-people" aria-hidden="true"></i><?= e(label('listing_gender', $l['gender_pref'])) ?></span><?php endif; ?>
      <?php foreach ($amen as $a): ?><span><i class="bi bi-check2" aria-hidden="true"></i><?= e(\Flatmates\Support\Options::AMENITIES[$a] ?? $a) ?></span><?php endforeach; ?>
    </div>
    <?= \Flatmates\Http\View::partial('partials/badges', ['keys' => array_merge($l['badges'] ?? [], array_values(array_intersect($l['owner_badges'] ?? [], ['college_email', 'student_id'])))]) ?>
  </div>
</article>
