<?php $scripts = ['swipe.js']; \Flatmates\Http\View::share('scripts', $scripts); ?>
<?php if ($problem === 'onboarding'): ?>
  <div class="text-center py-5"><h1 class="h4">Finish your profile to start matching</h1><a class="btn btn-primary mt-2" href="<?= e(url('/onboarding/1')) ?>">Continue setup</a></div>
<?php elseif ($problem): ?>
  <div class="alert alert-info"><?= e($problem) ?> <a href="<?= e(url('/onboarding/2')) ?>">Update profile</a></div>
<?php else: ?>
<div class="discover">
  <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h1 class="h5 mb-0">Flatmates in <?= e($me['city_name'] ?? '') ?></h1>
      <div class="small text-body-secondary"><span data-remaining><?= (int)$remaining ?></span> swipes left today<?= $pendingLikes ? ' · <i class="bi bi-heart-fill text-danger"></i> ' . (int)$pendingLikes . ' people liked you' : '' ?></div>
    </div>
    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filters" aria-expanded="false" aria-controls="filters"><i class="bi bi-sliders"></i> Filters<?= $filters ? ' (' . count($filters) . ')' : '' ?></button>
  </div>
  <?php if ((int)$me['completeness'] < $minCompleteness || !(int)$me['discoverable']): ?>
    <div class="alert alert-warning small"><i class="bi bi-eye-slash me-1"></i>Others can't see you yet: <?= !(int)$me['discoverable'] ? 'you turned off discovery.' : 'your profile is ' . (int)$me['completeness'] . '% complete (needs ' . $minCompleteness . '%).' ?> <a href="<?= e(url('/onboarding/' . (!(int)$me['discoverable'] ? 4 : 2))) ?>">Fix this</a></div>
  <?php endif; ?>
  <form class="collapse card card-body border-0 shadow-sm mb-3<?= $filters ? ' show' : '' ?>" id="filters" method="get" action="<?= e(url('/discover')) ?>">
    <div class="row g-2">
      <div class="col-md-4"><label class="form-label small" for="fcol">College</label><select class="form-select form-select-sm" id="fcol" name="college_id"><option value="">Any college</option><?php foreach ($colleges as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($filters['college_id'] ?? '', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="fbud">Max budget</label><input class="form-control form-control-sm" type="number" id="fbud" name="budget_max" min="1000" step="500" value="<?= e($filters['budget_max'] ?? '') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="fmove">Moving by</label><input class="form-control form-control-sm" type="date" id="fmove" name="move_in_before" value="<?= e($filters['move_in_before'] ?? '') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="froom">Room</label><select class="form-select form-select-sm" id="froom" name="room_type"><option value="">Any</option><option value="private"<?= selected($filters['room_type'] ?? '', 'private') ?>>Private</option><option value="shared"<?= selected($filters['room_type'] ?? '', 'shared') ?>>Shared</option></select></div>
      <div class="col-6 col-md-2"><label class="form-label small" for="fstat">Situation</label><select class="form-select form-select-sm" id="fstat" name="status"><option value="">Any</option><option value="has_room"<?= selected($filters['status'] ?? '', 'has_room') ?>>Has a room</option><option value="looking_flatmate"<?= selected($filters['status'] ?? '', 'looking_flatmate') ?>>Has a place</option><option value="looking_room"<?= selected($filters['status'] ?? '', 'looking_room') ?>>Looking too</option></select></div>
      <div class="col-12 d-flex flex-wrap gap-3 small">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="verified_only" value="1" id="fver"<?= checked(!empty($filters['verified_only'])) ?>><label class="form-check-label" for="fver">Verified students only</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="non_smoker" value="1" id="fns"<?= checked(!empty($filters['non_smoker'])) ?>><label class="form-check-label" for="fns">Non-smokers</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="diet" value="veg" id="fveg"<?= checked(!empty($filters['diet'])) ?>><label class="form-check-label" for="fveg">Vegetarian</label></div>
      </div>
      <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-sm">Apply</button><a class="btn btn-light btn-sm" href="<?= e(url('/discover')) ?>">Clear</a></div>
    </div>
  </form>

  <div class="deck-wrap">
    <div class="deck" id="deck" data-deck-url="<?= e(url('/api/deck', array_map('strval', $filters))) ?>" data-swipe-url="<?= e(url('/api/swipe')) ?>" aria-live="polite">
      <div class="deck-empty text-center p-4" hidden>
        <i class="bi bi-emoji-smile display-5 text-body-secondary"></i>
        <h2 class="h5 mt-2">That's everyone for now</h2>
        <p class="small text-body-secondary">New students join every day. Try widening your filters, or browse rooms meanwhile.</p>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/rooms', ['city_id' => $me['city_id']])) ?>">Browse rooms</a>
      </div>
      <div class="deck-loading text-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading…</span></div></div>
    </div>
    <div class="deck-actions">
      <button class="btn btn-pass" type="button" data-action="pass" aria-label="Pass (left arrow)"><i class="bi bi-x-lg"></i></button>
      <button class="btn btn-info-card" type="button" data-action="info" aria-label="View full profile"><i class="bi bi-info-lg"></i></button>
      <button class="btn btn-like" type="button" data-action="like" aria-label="Like (right arrow)"><i class="bi bi-heart-fill"></i></button>
    </div>
    <p class="text-center small text-body-secondary d-none d-md-block mt-2">Tip: use ← and → keys</p>
  </div>
</div>

<div class="modal fade" id="matchModal" tabindex="-1" aria-labelledby="matchTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center p-4 match-modal">
  <div class="display-4">🎉</div><h2 class="h3 mt-2" id="matchTitle">It's a match!</h2>
  <p class="text-body-secondary">You and <strong data-match-name></strong> liked each other. Say hi and plan a call or a visit in a public place.</p>
  <div class="d-grid gap-2"><a class="btn btn-primary btn-lg" data-match-chat href="#">Send a message</a><button class="btn btn-light" data-bs-dismiss="modal">Keep swiping</button></div>
</div></div></div>
<template id="cardTpl">
  <article class="swipe-card" tabindex="-1">
    <div class="swipe-photo"><img alt="" hidden><span class="swipe-initials"></span>
      <span class="stamp stamp-like">LIKE</span><span class="stamp stamp-pass">NOPE</span>
      <span class="score-badge"><b></b>% match</span></div>
    <div class="swipe-body">
      <h2 class="h4 mb-0"><span data-f="name"></span><span data-f="age"></span> <i class="bi bi-patch-check-fill text-primary" data-f="verified" hidden title="Verified student"></i></h2>
      <div class="small text-body-secondary" data-f="college"></div>
      <div class="d-flex flex-wrap gap-2 my-2 small"><span class="chip" data-f="status"></span><span class="chip" data-f="budget"></span><span class="chip" data-f="move_in"></span></div>
      <p class="small mb-2 bio" data-f="bio"></p>
      <div class="d-flex flex-wrap gap-1 small" data-f="reasons"></div>
      <div class="d-flex flex-wrap gap-1 small mt-1" data-f="tags"></div>
    </div>
  </article>
</template>
<?php endif; ?>
