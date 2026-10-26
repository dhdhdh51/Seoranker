<?php
use Flatmates\Support\Options;

/** @var array $l */
$u = $currentUser ?? null;
\Flatmates\Http\View::share('jsonLd', [
    '@context' => 'https://schema.org', '@type' => 'Accommodation', 'name' => $l['title'],
    'description' => str_limit((string)$l['description'], 300),
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => $l['locality'] . ', ' . $l['city_name'], 'addressCountry' => 'IN'],
    'image' => array_map(static fn($p) => absolute_url('/media/' . $p['path']), array_slice($l['photos'], 0, 3)),
    'amenityFeature' => array_map(static fn($a) => ['@type' => 'LocationFeatureSpecification', 'name' => Options::AMENITIES[$a] ?? $a, 'value' => true], $l['amenity_list']),
    'offers' => ['@type' => 'Offer', 'price' => (int)$l['rent'], 'priceCurrency' => 'INR', 'availability' => 'https://schema.org/InStock'],
]);
?>
<nav aria-label="breadcrumb" class="small"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= e(url('/rooms')) ?>">Rooms</a></li>
  <li class="breadcrumb-item"><a href="<?= e(url('/flatmates/' . $l['city_slug'])) ?>"><?= e($l['city_name']) ?></a></li>
  <li class="breadcrumb-item active" aria-current="page"><?= e(str_limit($l['title'], 40)) ?></li>
</ol></nav>
<?php if ($l['status'] !== 'published'): ?><div class="alert alert-warning small">Status: <strong><?= e(label('listing_status', $l['status'])) ?></strong><?= $l['moderation_note'] ? ' — ' . e($l['moderation_note']) : '' ?>. Only you and moderators can see this page.</div><?php endif; ?>
<div class="row g-4">
  <div class="col-lg-8">
    <?php if ($l['photos']): ?>
      <div id="gallery" class="carousel slide rounded-3 overflow-hidden mb-3 shadow-sm" data-bs-ride="false">
        <div class="carousel-inner ratio ratio-16x9">
          <?php foreach ($l['photos'] as $i => $p): ?><div class="carousel-item<?= $i === 0 ? ' active' : '' ?>"><img src="<?= e(media_url($p['path'])) ?>" class="d-block w-100 h-100 object-fit-cover" alt="Photo <?= $i + 1 ?> of <?= e($l['title']) ?>"<?= $i ? ' loading="lazy"' : '' ?>></div><?php endforeach; ?>
        </div>
        <?php if (count($l['photos']) > 1): ?>
          <button class="carousel-control-prev" type="button" data-bs-target="#gallery" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button>
          <button class="carousel-control-next" type="button" data-bs-target="#gallery" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next</span></button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <span class="badge text-bg-primary"><?= e(label('listing_type', $l['type'])) ?></span>
    <h1 class="h3 mt-2"><?= e($l['title']) ?></h1>
    <p class="text-body-secondary"><i class="bi bi-geo-alt"></i> <?= e($l['locality']) ?>, <?= e($l['city_name']) ?><?= $l['college_name'] ? ' · near <a href="' . e(url('/flatmates/' . $l['city_slug'] . '/' . $l['college_slug'])) . '">' . e($l['college_name']) . '</a>' : '' ?></p>
    <?php if ($l['address_private']): ?><div class="alert alert-success small"><i class="bi bi-house-lock me-1"></i><strong>Address:</strong> <?= e($l['address_private']) ?> <span class="text-body-secondary">(shared with you privately)</span></div><?php endif; ?>
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-4 facts">
      <div><div class="fact"><small>Rent</small><strong><?= e(inr($l['rent'])) ?>/mo</strong></div></div>
      <div><div class="fact"><small>Deposit</small><strong><?= $l['deposit'] !== null ? e(inr($l['deposit'])) : '—' ?></strong></div></div>
      <div><div class="fact"><small>Available</small><strong><?= e($l['available_from'] ? fmt_date($l['available_from']) : 'Now') ?></strong></div></div>
      <div><div class="fact"><small>For</small><strong><?= e(label('listing_gender', $l['gender_pref'])) ?></strong></div></div>
      <?php if ($l['furnishing']): ?><div><div class="fact"><small>Furnishing</small><strong><?= e(label('furnishing', $l['furnishing'])) ?></strong></div></div><?php endif; ?>
      <?php if ($l['beds_available']): ?><div><div class="fact"><small>Beds free</small><strong><?= (int)$l['beds_available'] ?><?= $l['total_beds'] ? ' of ' . (int)$l['total_beds'] : '' ?></strong></div></div><?php endif; ?>
    </div>
    <h2 class="h5">About this place</h2>
    <p class="text-pre"><?= e($l['description']) ?></p>
    <?php if ($l['amenity_list']): ?>
      <h2 class="h5 mt-4">Amenities</h2>
      <div class="d-flex flex-wrap gap-2"><?php foreach ($l['amenity_list'] as $a): ?><span class="chip"><i class="bi bi-check2"></i> <?= e(Options::AMENITIES[$a] ?? $a) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm border-0 sticky-lg-top" style="top:5rem">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3"><?= avatar($l['owner_photo'], (string)$l['owner_name'], 'md') ?><div><div class="small text-body-secondary">Posted by</div><strong><?= e($l['owner_name']) ?></strong><?php if ($l['owner_verified']): ?> <i class="bi bi-patch-check-fill text-primary" title="Verified student"></i><?php endif; ?></div></div>
        <?php if ($l['is_owner']): ?>
          <a class="btn btn-primary w-100" href="<?= e(url('/my/listings/' . (int)$l['id'] . '/edit')) ?>">Edit listing</a>
        <?php elseif (!$u): ?>
          <a class="btn btn-primary w-100" href="<?= e(url('/register')) ?>">Join free to contact</a>
          <p class="small text-body-secondary mt-2 mb-0">Contact details are never shown publicly. Sign in to send an enquiry or request a viewing.</p>
        <?php elseif ($myEnquiry && $myEnquiry['status'] === 'new'): ?>
          <div class="alert alert-info small mb-0">Your <?= e($myEnquiry['kind']) ?> was sent <?= e(ago($myEnquiry['created_at'])) ?>. You'll be notified when the owner replies.</div>
        <?php elseif ($myEnquiry && $myEnquiry['status'] === 'accepted' && $myEnquiry['conversation_id']): ?>
          <a class="btn btn-primary w-100" href="<?= e(url('/chat/' . (int)$myEnquiry['conversation_id'])) ?>"><i class="bi bi-chat-dots"></i> Open chat</a>
        <?php elseif ($u->isStudent()): ?>
          <form method="post" action="<?= e(url('/rooms/' . (int)$l['id'] . '/enquire')) ?>">
            <?= csrf_field() ?>
            <div class="btn-group w-100 mb-2" role="group" aria-label="Request type">
              <input type="radio" class="btn-check" name="kind" id="k1" value="enquiry" checked><label class="btn btn-outline-primary btn-sm" for="k1">Enquire</label>
              <input type="radio" class="btn-check" name="kind" id="k2" value="viewing"><label class="btn btn-outline-primary btn-sm" for="k2">Request viewing</label>
            </div>
            <label class="form-label small" for="vat">Preferred viewing time (for viewings)</label>
            <input class="form-control form-control-sm mb-2<?= invalid($errors, 'viewing_at') ?>" type="datetime-local" id="vat" name="viewing_at"><?= field_error($errors, 'viewing_at') ?>
            <label class="visually-hidden" for="emsg">Message</label>
            <textarea class="form-control form-control-sm mb-2<?= invalid($errors, 'message') ?>" id="emsg" name="message" rows="4" maxlength="1000" required placeholder="Hi! I'm a 2nd-year student at … looking to move in from …"><?= e(old('message')) ?></textarea><?= field_error($errors, 'message') ?>
            <button class="btn btn-primary w-100">Send</button>
          </form>
        <?php endif; ?>
        <?php if ($u && !$l['is_owner']): ?>
          <div class="d-flex justify-content-between mt-3">
            <?php if ($u->isStudent()): ?><button class="btn btn-sm btn-link" type="button" data-save="listing" data-id="<?= (int)$l['id'] ?>" aria-pressed="<?= $saved ? 'true' : 'false' ?>"><i class="bi bi-bookmark<?= $saved ? '-fill' : '' ?>"></i> <span><?= $saved ? 'Saved' : 'Save' ?></span></button><?php endif; ?>
            <button class="btn btn-sm btn-link text-danger" type="button" data-report="listing" data-id="<?= (int)$l['id'] ?>"><i class="bi bi-flag"></i> Report</button>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-footer small text-body-secondary bg-transparent"><i class="bi bi-shield-exclamation me-1"></i>Never pay a token amount or deposit before seeing the place in person.</div>
    </div>
  </div>
</div>
