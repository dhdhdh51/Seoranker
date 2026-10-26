<?php
use Flatmates\Http\View;
use Flatmates\Support\Options;

/** @var array $l */
$u = $currentUser ?? null;
$icons = ['wifi' => 'wifi', 'ac' => 'snow', 'washing_machine' => 'water', 'fridge' => 'box', 'kitchen' => 'egg-fried', 'geyser' => 'droplet-half', 'power_backup' => 'lightning-charge', 'parking' => 'p-square', 'security' => 'camera-video', 'lift' => 'arrow-down-up', 'meals' => 'cup-hot', 'housekeeping' => 'stars', 'attached_bath' => 'badge-wc', 'balcony' => 'tree', 'wheelchair' => 'universal-access'];
$reviewed = in_array('listing_reviewed', $l['badges'], true);
$ownerTrust = array_values(array_intersect($l['owner_badges'], ['email_verified', 'college_email', 'student_id']));
View::share('jsonLd', [
    '@context' => 'https://schema.org', '@type' => 'Accommodation', 'name' => $l['title'],
    'description' => str_limit((string)$l['description'], 300),
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => $l['locality'] . ', ' . $l['city_name'], 'addressCountry' => 'IN'],
    'image' => array_map(static fn($p) => absolute_url('/media/' . $p['path']), array_slice($l['photos'], 0, 3)),
    'amenityFeature' => array_map(static fn($a) => ['@type' => 'LocationFeatureSpecification', 'name' => Options::AMENITIES[$a] ?? $a, 'value' => true], $l['amenity_list']),
]);
View::share('scripts', ['listing.js']);
$canEnquire = $u && $u->isStudent() && !$l['is_owner'] && !($myEnquiry && in_array($myEnquiry['status'], ['new', 'accepted'], true));
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= e(url('/rooms')) ?>">Rooms</a></li>
  <li class="breadcrumb-item"><a href="<?= e(url('/flatmates/' . $l['city_slug'])) ?>"><?= e($l['city_name']) ?></a></li>
  <li class="breadcrumb-item active text-truncate" aria-current="page"><?= e(str_limit($l['title'], 40)) ?></li>
</ol></nav>
<?php if ($l['status'] !== 'published'): ?><div class="notice notice-info mb-3"><i class="bi bi-eye-slash" aria-hidden="true"></i><div>Status: <strong><?= e(label('listing_status', $l['status'])) ?></strong><?= $l['moderation_note'] ? ' — ' . e($l['moderation_note']) : '' ?>. Only you and moderators can see this page.</div></div><?php endif; ?>

<div class="row g-4 g-xl-5">
  <div class="col-lg-8">
    <?php if ($l['photos']): ?>
      <div class="gallery mb-4" data-gallery>
        <div class="gallery-main">
          <img src="<?= e(media_url($l['photos'][0]['path'])) ?>" alt="Photo 1 of <?= count($l['photos']) ?>: <?= e($l['title']) ?>" width="1280" height="800" fetchpriority="high" data-gallery-main>
          <span class="media-pill gallery-count" data-gallery-count>1 / <?= count($l['photos']) ?></span>
        </div>
        <?php if (count($l['photos']) > 1): ?>
        <div class="gallery-thumbs" role="list" aria-label="Photos">
          <?php foreach ($l['photos'] as $i => $p): ?>
            <button type="button" role="listitem" data-full="<?= e(media_url($p['path'])) ?>" data-index="<?= $i + 1 ?>" aria-label="Show photo <?= $i + 1 ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>><img src="<?= e(media_url($p['path'], 'sm')) ?>" alt="" loading="lazy" width="84" height="64"></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="gallery mb-4"><div class="gallery-main d-grid" style="place-items:center"><div class="text-center text-body-secondary"><i class="bi bi-image fs-1" aria-hidden="true"></i><div class="small">No photos yet — ask the owner for some before visiting.</div></div></div></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
      <span class="badge text-bg-primary"><?= e(label('listing_type', $l['type'])) ?></span>
      <?= View::partial('partials/badges', ['keys' => $l['badges']]) ?>
    </div>
    <h1 style="font-size:var(--fm-fs-h2)"><?= e($l['title']) ?></h1>
    <p class="text-body-secondary"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= e($l['locality']) ?>, <?= e($l['city_name']) ?><?= $l['college_name'] ? ' · near <a href="' . e(url('/flatmates/' . $l['city_slug'] . '/' . $l['college_slug'])) . '">' . e($l['college_name']) . '</a>' : '' ?></p>
    <?php if ($l['address_private']): ?><div class="notice notice-info mb-3"><i class="bi bi-house-lock" aria-hidden="true"></i><div><strong>Address:</strong> <?= e($l['address_private']) ?> <span class="text-body-secondary">(shared with you privately — please don't repost it)</span></div></div><?php endif; ?>

    <div class="row row-cols-2 row-cols-md-4 g-2 mb-4 facts">
      <div><div class="fact"><small>Available</small><strong><?= e($l['available_from'] && strtotime($l['available_from']) > time() ? fmt_date($l['available_from']) : 'Now') ?></strong></div></div>
      <div><div class="fact"><small>Suitable for</small><strong><?= e(label('listing_gender', $l['gender_pref'])) ?></strong></div></div>
      <div><div class="fact"><small>Furnishing</small><strong><?= e($l['furnishing'] ? label('furnishing', $l['furnishing']) : 'Not stated') ?></strong></div></div>
      <div><div class="fact"><small>Beds free</small><strong><?= $l['beds_available'] ? (int)$l['beds_available'] . ($l['total_beds'] ? ' of ' . (int)$l['total_beds'] : '') : 'Not stated' ?></strong></div></div>
    </div>

    <h2 class="h5">About this place</h2>
    <p class="text-pre"><?= e($l['description']) ?></p>
    <?php if ($l['amenity_list']): ?>
      <h2 class="h5 mt-4">Amenities</h2>
      <div class="amenity-grid"><?php foreach ($l['amenity_list'] as $a): ?><div class="amenity"><i class="bi bi-<?= e($icons[$a] ?? 'check2') ?>" aria-hidden="true"></i><?= e(Options::AMENITIES[$a] ?? $a) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <h2 class="h5 mt-4">Trust information</h2>
    <div class="surface p-3">
      <div class="trust-row"><span class="trust-ico <?= $reviewed ? 'ok' : 'off' ?>"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span><div><strong><?= $reviewed ? 'Listing Reviewed' : 'Not reviewed by a moderator yet' ?></strong><div class="small text-body-secondary"><?= $reviewed ? e(\Flatmates\Services\TrustService::BADGES['listing_reviewed']['means'] . ' ' . \Flatmates\Services\TrustService::BADGES['listing_reviewed']['not']) : 'This version of the listing hasn\'t been checked by our team. Take extra care.' ?></div></div></div>
      <div class="trust-row"><span class="trust-ico <?= $ownerTrust ? 'ok' : 'off' ?>"><i class="bi bi-person-check" aria-hidden="true"></i></span><div><strong>About the person who posted it</strong><div class="mt-1"><?= $ownerTrust ? View::partial('partials/badges', ['keys' => $ownerTrust]) : '<span class="small text-body-secondary">No verification badges yet.</span>' ?></div></div></div>
      <div class="trust-row"><span class="trust-ico off"><i class="bi bi-building-x" aria-hidden="true"></i></span><div><strong>Ownership is not verified</strong><div class="small text-body-secondary">We don't check property ownership or whether the poster is allowed to rent it out. Ask before paying anything.</div></div></div>
    </div>
    <?= View::partial('partials/safety_notice', ['class' => 'mt-4']) ?>
  </div>

  <div class="col-lg-4">
    <div class="sticky-cta d-grid gap-3">
      <div class="surface p-4" id="enquire">
        <div class="listing-price" style="font-size:1.6rem"><?= e(inr($l['rent'])) ?> <small>/ month</small></div>
        <table class="cost-table mt-3" aria-label="Costs">
          <tr><td>Monthly rent</td><td><?= e(inr($l['rent'])) ?></td></tr>
          <tr><td>Security deposit <span class="text-body-secondary">(usually refundable)</span></td><td><?= $l['deposit'] !== null ? e(inr($l['deposit'])) : 'Not stated' ?></td></tr>
          <?php if ($l['deposit'] !== null): ?><tr class="total"><td>Typical move-in cost</td><td><?= e(inr((int)$l['rent'] + (int)$l['deposit'])) ?></td></tr><?php endif; ?>
        </table>
        <p class="small text-body-secondary mt-2 mb-3">Electricity, maintenance, food or other charges aren't included unless the description says so. Confirm every charge in writing.</p>
        <div class="d-flex align-items-center gap-2 mb-3"><?= avatar($l['owner_photo'], (string)$l['owner_name'], 'md') ?><div class="min-w-0"><div class="small text-body-secondary">Posted by</div><strong><?= e($l['owner_name']) ?></strong></div></div>
        <?php if ($l['is_owner']): ?>
          <a class="btn btn-primary w-100" href="<?= e(url('/my/listings/' . (int)$l['id'] . '/edit')) ?>">Edit listing</a>
        <?php elseif (!$u): ?>
          <a class="btn btn-primary w-100" href="<?= e(url('/register')) ?>">Join free to contact</a>
          <p class="small text-body-secondary mt-2 mb-0">Contact details are never public. Sign in to send an enquiry or request a viewing.</p>
        <?php elseif ($myEnquiry && $myEnquiry['status'] === 'new'): ?>
          <div class="notice notice-info"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div>Your <?= e($myEnquiry['kind']) ?> was sent <?= e(ago($myEnquiry['created_at'])) ?>. You'll be notified when the owner replies.</div></div>
        <?php elseif ($myEnquiry && $myEnquiry['status'] === 'accepted' && $myEnquiry['conversation_id']): ?>
          <a class="btn btn-primary w-100" href="<?= e(url('/chat/' . (int)$myEnquiry['conversation_id'])) ?>"><i class="bi bi-chat-dots" aria-hidden="true"></i> Open chat</a>
        <?php elseif ($canEnquire): ?>
          <form method="post" action="<?= e(url('/rooms/' . (int)$l['id'] . '/enquire')) ?>" data-loading>
            <?= csrf_field() ?>
            <div class="btn-group w-100 mb-3" role="group" aria-label="Request type">
              <input type="radio" class="btn-check" name="kind" id="k1" value="enquiry" checked><label class="btn btn-outline-primary btn-sm" for="k1">Ask a question</label>
              <input type="radio" class="btn-check" name="kind" id="k2" value="viewing"><label class="btn btn-outline-primary btn-sm" for="k2">Request a viewing</label>
            </div>
            <div class="mb-2" data-viewing-field hidden><label class="form-label" for="vat">Preferred viewing time</label><input class="form-control form-control-sm<?= invalid($errors, 'viewing_at') ?>" type="datetime-local" id="vat" name="viewing_at"><?= field_error($errors, 'viewing_at') ?></div>
            <label class="form-label" for="emsg">Message</label>
            <textarea class="form-control mb-3<?= invalid($errors, 'message') ?>" id="emsg" name="message" rows="4" maxlength="1000" required placeholder="Hi! I'm a 2nd-year student at … looking to move in from …"><?= e(old('message')) ?></textarea><?= field_error($errors, 'message') ?>
            <button class="btn btn-primary w-100"><i class="bi bi-send" aria-hidden="true"></i> Send enquiry</button>
            <p class="small text-body-secondary mt-2 mb-0">The owner sees your display name and profile, not your email or phone.</p>
          </form>
        <?php endif; ?>
        <?php if ($u && !$l['is_owner']): ?>
          <div class="d-flex justify-content-between mt-3 pt-3 border-top">
            <?php if ($u->isStudent()): ?><button class="btn btn-sm btn-light" type="button" data-save="listing" data-id="<?= (int)$l['id'] ?>" aria-pressed="<?= $saved ? 'true' : 'false' ?>"><i class="bi bi-bookmark<?= $saved ? '-fill' : '' ?>" aria-hidden="true"></i> <span><?= $saved ? 'Saved' : 'Save' ?></span></button><?php endif; ?>
            <button class="btn btn-sm btn-link text-danger" type="button" data-report="listing" data-id="<?= (int)$l['id'] ?>"><i class="bi bi-flag" aria-hidden="true"></i> Report listing</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php if ($canEnquire): ?><div class="mobile-cta d-flex align-items-center gap-3"><div class="flex-grow-1"><div class="fw-800" style="font-weight:800"><?= e(inr($l['rent'])) ?><span class="small text-body-secondary fw-normal"> / month</span></div><?php if ($l['deposit'] !== null): ?><div class="small text-body-secondary">+ <?= e(inr($l['deposit'])) ?> deposit</div><?php endif; ?></div><a class="btn btn-primary" href="#enquire">Enquire</a></div><?php endif; ?>
