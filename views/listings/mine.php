<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">My listings</h1><a class="btn btn-primary btn-sm" href="<?= e(url('/my/listings/new')) ?>"><i class="bi bi-plus-lg"></i> New listing</a></div>
<?php if (!$rows): ?>
  <div class="text-center py-5 text-body-secondary"><i class="bi bi-house-add display-5"></i><p class="mt-2">Have a vacant bed or room? List it free and find a compatible student flatmate.</p></div>
<?php else: ?>
<div class="list-group shadow-sm">
<?php foreach ($rows as $l): ?>
  <div class="list-group-item d-flex gap-3 align-items-center">
    <div class="thumb-sm"><?php if ($l['cover']): ?><img src="<?= e(media_url($l['cover'])) ?>" alt=""><?php else: ?><i class="bi bi-image"></i><?php endif; ?></div>
    <div class="flex-grow-1 min-w-0">
      <a class="fw-semibold text-truncate d-block" href="<?= e(url('/my/listings/' . (int)$l['id'] . '/edit')) ?>"><?= e($l['title']) ?></a>
      <div class="small text-body-secondary"><?= e(inr($l['rent'])) ?>/mo · <?= e($l['locality']) ?>, <?= e($l['city_name']) ?> · <?= (int)$l['views'] ?> views</div>
      <?php if ($l['moderation_note'] && in_array($l['status'], ['rejected', 'hidden'], true)): ?><div class="small text-danger"><?= e($l['moderation_note']) ?></div><?php endif; ?>
    </div>
    <div class="text-end">
      <span class="badge <?= $l['status'] === 'published' ? 'text-bg-success' : ($l['status'] === 'pending' ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= e(label('listing_status', $l['status'])) ?></span>
      <?php if ($l['new_enquiries']): ?><a class="d-block small mt-1" href="<?= e(url('/enquiries')) ?>"><?= (int)$l['new_enquiries'] ?> new enquir<?= $l['new_enquiries'] > 1 ? 'ies' : 'y' ?></a><?php endif; ?>
      <?php if ($l['status'] === 'draft'): ?><form method="post" action="<?= e(url('/my/listings/' . (int)$l['id'] . '/submit')) ?>" class="mt-1"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Submit</button></form><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
