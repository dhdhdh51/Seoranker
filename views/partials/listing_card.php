<?php /** @var array $l */ ?>
<article class="card listing-card h-100 shadow-sm">
  <a href="<?= e(url('/rooms/' . $l['slug'])) ?>" class="ratio ratio-4x3 listing-thumb d-block" aria-label="<?= e($l['title']) ?>">
    <?php if (!empty($l['cover'])): ?><img src="<?= e(media_url($l['cover'])) ?>" alt="" loading="lazy" class="object-fit-cover"><?php else: ?><span class="thumb-empty"><i class="bi bi-house-door"></i></span><?php endif; ?>
  </a>
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <span class="badge text-bg-light border"><?= e(label('listing_type', $l['type'])) ?></span>
      <strong class="text-nowrap"><?= e(inr($l['rent'])) ?><small class="text-body-secondary fw-normal">/mo</small></strong>
    </div>
    <h3 class="h6 mt-2 mb-1"><a class="stretched-link text-reset text-decoration-none" href="<?= e(url('/rooms/' . $l['slug'])) ?>"><?= e($l['title']) ?></a></h3>
    <div class="small text-body-secondary"><i class="bi bi-geo-alt"></i> <?= e($l['locality']) ?>, <?= e($l['city_name']) ?></div>
    <div class="small mt-2 d-flex flex-wrap gap-2">
      <?php if (($l['gender_pref'] ?? 'any') !== 'any'): ?><span class="chip"><?= e(label('listing_gender', $l['gender_pref'])) ?></span><?php endif; ?>
      <?php if (!empty($l['available_from'])): ?><span class="chip">From <?= e(fmt_date($l['available_from'])) ?></span><?php endif; ?>
      <?php if (!empty($l['owner_verified'])): ?><span class="chip chip-ok"><i class="bi bi-patch-check-fill"></i> Verified student</span><?php endif; ?>
    </div>
  </div>
</article>
