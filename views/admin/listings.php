<?php use Flatmates\Support\Options; ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h1 class="h4 mb-0">Listings</h1>
<div class="btn-group btn-group-sm flex-wrap"><?php foreach (['pending' => 'Awaiting review', 'published' => 'Live', 'hidden' => 'Hidden', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $v): ?><a class="btn btn-outline-secondary<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/listings', ['status' => $k])) ?>"><?= e($v) ?></a><?php endforeach; ?></div></div>
<?php if (!$rows): ?><p class="text-body-secondary">Nothing here.</p><?php endif; ?>
<div class="vstack gap-2">
<?php foreach ($rows as $l): ?>
  <div class="card border-0 shadow-sm"><div class="card-body small d-flex flex-wrap gap-3 justify-content-between">
    <div class="min-w-0">
      <a class="fw-semibold" href="<?= e(url('/rooms/' . $l['slug'])) ?>" target="_blank"><?= e($l['title']) ?></a> <span class="badge text-bg-secondary"><?= e(label('listing_status', $l['status'])) ?></span><?= $l['open_reports'] ? ' <span class="badge text-bg-danger">' . (int)$l['open_reports'] . ' reports</span>' : '' ?>
      <div class="text-body-secondary"><?= e(label('listing_type', $l['type'])) ?> · <?= e(inr($l['rent'])) ?> · <?= e($l['locality']) ?>, <?= e($l['city_name']) ?> · by <?= e($l['owner_name'] ?? '') ?> · updated <?= e(ago($l['updated_at'])) ?></div>
      <?php if ($l['moderation_note']): ?><div class="text-body-secondary fst-italic"><?= e($l['moderation_note']) ?></div><?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('/admin/listings/' . (int)$l['id'])) ?>" class="d-flex gap-1 align-items-start flex-wrap"><?= csrf_field() ?>
      <label class="visually-hidden" for="n<?= (int)$l['id'] ?>">Note</label><input class="form-control form-control-sm" id="n<?= (int)$l['id'] ?>" name="note" placeholder="Note to owner" maxlength="500" style="max-width:14rem">
      <?php if ($l['status'] !== 'published'): ?><button class="btn btn-sm btn-success" name="action" value="approve">Approve</button><?php endif; ?>
      <?php if ($l['status'] !== 'rejected'): ?><button class="btn btn-sm btn-outline-warning" name="action" value="reject">Reject</button><?php endif; ?>
      <?php if ($l['status'] !== 'hidden'): ?><button class="btn btn-sm btn-outline-danger" name="action" value="hide">Hide</button><?php endif; ?>
    </form>
  </div></div>
<?php endforeach; ?>
</div>
<?= \Flatmates\Http\View::partial('partials/pager', ['pager' => $pager]) ?>
