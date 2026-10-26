<?php
use Flatmates\Services\ListingService;
use Flatmates\Support\Options;

$l = $l ?? null;
$v = static fn(string $f, $d = '') => old($f, $l[$f] ?? $d);
$amen = is_array(old('amenities', null)) ? old('amenities') : array_filter(explode(',', (string)($l['amenities'] ?? '')));
$action = $l ? url('/my/listings/' . (int)$l['id']) : url('/my/listings');
?>
<div class="row justify-content-center"><div class="col-lg-8">
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><?= $l ? 'Edit listing' : 'Post a room, flat or vacant bed' ?></h1>
  <?php if ($l): ?><span class="badge text-bg-secondary"><?= e(label('listing_status', $l['status'])) ?></span><?php endif; ?>
</div>
<?php if ($l && $l['moderation_note']): ?><div class="alert alert-warning small"><strong>Moderator note:</strong> <?= e($l['moderation_note']) ?></div><?php endif; ?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="card card-body shadow-sm border-0">
  <?= csrf_field() ?>
  <fieldset class="mb-3"><legend class="form-label fs-6">What are you listing?</legend>
    <div class="d-flex flex-wrap gap-2"><?php foreach (Options::GROUPS['listing_type'] as $k => $t): ?>
      <input type="radio" class="btn-check" name="type" value="<?= e($k) ?>" id="t_<?= e($k) ?>"<?= checked($v('type') === $k) ?> required><label class="btn btn-outline-primary" for="t_<?= e($k) ?>"><?= e($t) ?></label>
    <?php endforeach; ?></div><?= field_error($errors, 'type') ?></fieldset>
  <div class="mb-3"><label class="form-label" for="title">Title</label><input class="form-control<?= invalid($errors, 'title') ?>" id="title" name="title" value="<?= e($v('title')) ?>" maxlength="140" required placeholder="e.g. Furnished room in 3BHK, 5 min walk to Amity"><?= field_error($errors, 'title') ?></div>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="city_id">City</label><select class="form-select<?= invalid($errors, 'city_id') ?>" id="city_id" name="city_id" required data-city-select="#near_college_id"><option value="">Choose…</option><?php foreach ($cities as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($v('city_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= field_error($errors, 'city_id') ?></div>
    <div class="col-md-6"><label class="form-label" for="locality">Locality / sector</label><input class="form-control<?= invalid($errors, 'locality') ?>" id="locality" name="locality" value="<?= e($v('locality')) ?>" maxlength="120" required><?= field_error($errors, 'locality') ?></div>
    <div class="col-md-6"><label class="form-label" for="near_college_id">Nearest college</label><select class="form-select" id="near_college_id" name="near_college_id"><option value="">—</option><?php foreach ($colleges as $c): ?><option value="<?= (int)$c['id'] ?>" data-city="<?= (int)$c['city_id'] ?>"<?= selected($v('near_college_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label class="form-label" for="address_private">Full address <span class="small text-body-secondary">(private)</span></label><input class="form-control" id="address_private" name="address_private" value="<?= e($v('address_private')) ?>" maxlength="300"><div class="form-text">Only shown to students whose enquiry you accept.</div></div>
    <div class="col-6 col-md-3"><label class="form-label" for="rent">Rent ₹/month</label><input class="form-control<?= invalid($errors, 'rent') ?>" type="number" inputmode="numeric" id="rent" name="rent" min="500" step="100" value="<?= e($v('rent')) ?>" required><?= field_error($errors, 'rent') ?></div>
    <div class="col-6 col-md-3"><label class="form-label" for="deposit">Deposit ₹</label><input class="form-control<?= invalid($errors, 'deposit') ?>" type="number" inputmode="numeric" id="deposit" name="deposit" min="0" step="100" value="<?= e($v('deposit')) ?>"><?= field_error($errors, 'deposit') ?></div>
    <div class="col-6 col-md-3"><label class="form-label" for="available_from">Available from</label><input class="form-control" type="date" id="available_from" name="available_from" value="<?= e($v('available_from')) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="gender_pref">Suitable for</label><select class="form-select" id="gender_pref" name="gender_pref"><?php foreach (Options::GROUPS['listing_gender'] as $k => $t): ?><option value="<?= e($k) ?>"<?= selected($v('gender_pref', 'any'), $k) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="furnishing">Furnishing</label><select class="form-select" id="furnishing" name="furnishing"><option value="">—</option><?php foreach (Options::GROUPS['furnishing'] as $k => $t): ?><option value="<?= e($k) ?>"<?= selected($v('furnishing'), $k) ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-4"><label class="form-label" for="total_beds">Total beds</label><input class="form-control" type="number" id="total_beds" name="total_beds" min="1" max="20" value="<?= e($v('total_beds')) ?>"></div>
    <div class="col-6 col-md-4"><label class="form-label" for="beds_available">Beds available</label><input class="form-control<?= invalid($errors, 'beds_available') ?>" type="number" id="beds_available" name="beds_available" min="1" max="20" value="<?= e($v('beds_available')) ?>"><?= field_error($errors, 'beds_available') ?></div>
  </div>
  <fieldset class="my-3"><legend class="form-label fs-6">Amenities</legend><div class="d-flex flex-wrap gap-2">
    <?php foreach (Options::AMENITIES as $k => $t): ?><input type="checkbox" class="btn-check" name="amenities[]" value="<?= e($k) ?>" id="a_<?= e($k) ?>"<?= checked(in_array($k, $amen, true)) ?>><label class="btn btn-sm btn-outline-secondary rounded-pill" for="a_<?= e($k) ?>"><?= e($t) ?></label><?php endforeach; ?>
  </div></fieldset>
  <div class="mb-3"><label class="form-label" for="description">Description</label><textarea class="form-control<?= invalid($errors, 'description') ?>" id="description" name="description" rows="6" maxlength="5000" required placeholder="Flatmates, house rules, what's included in rent, distance to college/metro…"><?= e($v('description')) ?></textarea><?= field_error($errors, 'description') ?><div class="form-text">No phone numbers or emails — interested students contact you in the app.</div></div>
  <div class="mb-3"><label class="form-label" for="visibility">Who can see it?</label><select class="form-select" id="visibility" name="visibility"><option value="public"<?= selected($v('visibility', 'public'), 'public') ?>>Everyone (shows on Google)</option><option value="members"<?= selected($v('visibility'), 'members') ?>>Signed-in students only</option></select></div>
  <?php if (!$l): ?><div class="mb-3"><label class="form-label" for="photos">Photos (up to <?= ListingService::MAX_PHOTOS ?>)</label><input class="form-control" type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple><div class="form-text">Real photos of the room. Faces and documents should not be visible.</div></div><?php endif; ?>
  <div class="d-flex flex-wrap gap-2 justify-content-end">
    <button class="btn btn-outline-primary" name="save_draft" value="1"><?= $l && $l['status'] !== 'draft' ? 'Save changes' : 'Save draft' ?></button>
    <?php if (!$l || in_array($l['status'], ['draft', 'rejected', 'closed'], true)): ?><button class="btn btn-primary" name="submit_review" value="1"><?= setting('rules.listing_review') ? 'Submit for review' : 'Publish' ?></button><?php endif; ?>
  </div>
</form>

<?php if ($l): ?>
<div class="card card-body shadow-sm border-0 mt-3">
  <h2 class="h6">Photos (<?= count($photos) ?>/<?= ListingService::MAX_PHOTOS ?>)</h2>
  <div class="row g-2 mb-3">
    <?php foreach ($photos as $p): ?>
      <div class="col-4 col-md-3"><div class="position-relative"><img class="img-fluid rounded ratio-4x3 object-fit-cover w-100" src="<?= e(media_url($p['path'])) ?>" alt="">
        <form method="post" action="<?= e(url('/my/listings/' . (int)$l['id'] . '/photos/' . (int)$p['id'] . '/delete')) ?>" class="position-absolute top-0 end-0 m-1" data-confirm="Delete this photo?"><?= csrf_field() ?><button class="btn btn-sm btn-danger" aria-label="Delete photo"><i class="bi bi-trash"></i></button></form></div></div>
    <?php endforeach; ?>
  </div>
  <?php if (count($photos) < ListingService::MAX_PHOTOS): ?>
  <form method="post" action="<?= e(url('/my/listings/' . (int)$l['id'] . '/photos')) ?>" enctype="multipart/form-data" class="d-flex gap-2"><?= csrf_field() ?>
    <label class="visually-hidden" for="addphotos">Add photos</label><input class="form-control<?= invalid($errors, 'photos') . invalid($errors, 'photo') ?>" type="file" id="addphotos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
    <button class="btn btn-outline-primary text-nowrap">Upload</button></form><?= field_error($errors, 'photos') ?><?= field_error($errors, 'photo') ?>
  <?php endif; ?>
</div>
<div class="d-flex gap-2 mt-3 justify-content-end">
  <?php if ($l['status'] === 'published'): ?><a class="btn btn-light" href="<?= e(url('/rooms/' . $l['slug'])) ?>">View live</a><form method="post" action="<?= e(url('/my/listings/' . (int)$l['id'] . '/close')) ?>" data-confirm="Close this listing? It will disappear from search."><?= csrf_field() ?><button class="btn btn-outline-secondary">Mark as filled</button></form><?php endif; ?>
  <form method="post" action="<?= e(url('/my/listings/' . (int)$l['id'] . '/delete')) ?>" data-confirm="Delete this listing permanently?"><?= csrf_field() ?><button class="btn btn-outline-danger">Delete</button></form>
</div>
<?php endif; ?>
</div></div>
