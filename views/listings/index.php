<?php use Flatmates\Services\ListingService; use Flatmates\Support\Options; $f = $filters; ?>
<div class="page-head">
  <div><h1><?= e($title) ?></h1><p><?= (int)$total ?> listing<?= $total === 1 ? '' : 's' ?> posted by students · no brokerage<?= !empty($f['q']) ? ' · matching "' . e($f['q']) . '"' : '' ?></p></div>
  <div class="d-flex gap-2">
    <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#roomFilters" aria-controls="roomFilters"><i class="bi bi-sliders" aria-hidden="true"></i> Filters<?= count(array_diff_key($f, ['sort' => 1])) ? ' (' . count(array_diff_key($f, ['sort' => 1])) . ')' : '' ?></button>
    <?php if (!empty($currentUser) && $currentUser->isStudent()): ?><a class="btn btn-primary" href="<?= e(url('/my/listings/new')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Post a room</a><?php endif; ?>
  </div>
</div>
<div class="row g-4">
  <aside class="col-lg-3">
    <div class="offcanvas-lg offcanvas-start surface p-lg-3" tabindex="-1" id="roomFilters" aria-labelledby="roomFiltersLabel">
      <div class="offcanvas-header"><h2 class="offcanvas-title h5" id="roomFiltersLabel">Filters</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#roomFilters" aria-label="Close"></button></div>
      <div class="offcanvas-body">
      <form method="get" action="<?= e(url('/rooms')) ?>" class="w-100 filter-form">
        <label class="form-label small" for="q">Search</label>
        <input class="form-control form-control-sm mb-2" id="q" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Area or keyword">
        <label class="form-label small" for="city">City</label>
        <select class="form-select form-select-sm mb-2" id="city" name="city_id" data-city-select="#col"><option value="">All cities</option><?php foreach ($cities as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($f['city_id'] ?? '', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="col">Near college</label>
        <select class="form-select form-select-sm mb-2" id="col" name="college_id" data-load-colleges="1"><option value="">Any</option><?php foreach ($colleges as $c): ?><option value="<?= (int)$c['id'] ?>" data-city="<?= (int)$c['city_id'] ?>"<?= selected($f['college_id'] ?? '', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="type">Type</label>
        <select class="form-select form-select-sm mb-2" id="type" name="type"><option value="">Any</option><?php foreach (Options::GROUPS['listing_type'] as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($f['type'] ?? '', $k) ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small" for="minr">Min ₹</label><input class="form-control form-control-sm" type="number" id="minr" name="min_rent" min="0" step="500" value="<?= e($f['min_rent'] ?? '') ?>"></div><div class="col-6"><label class="form-label small" for="maxr">Max ₹</label><input class="form-control form-control-sm" type="number" id="maxr" name="max_rent" min="0" step="500" value="<?= e($f['max_rent'] ?? '') ?>"></div></div>
        <label class="form-label small" for="gender">Suitable for</label>
        <select class="form-select form-select-sm mb-2" id="gender" name="gender"><option value="">Anyone</option><option value="female"<?= selected($f['gender'] ?? '', 'female') ?>>Women</option><option value="male"<?= selected($f['gender'] ?? '', 'male') ?>>Men</option></select>
        <label class="form-label small" for="furn">Furnishing</label>
        <select class="form-select form-select-sm mb-2" id="furn" name="furnishing"><option value="">Any</option><?php foreach (Options::GROUPS['furnishing'] as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($f['furnishing'] ?? '', $k) ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <label class="form-label small" for="avail">Available by</label>
        <input class="form-control form-control-sm mb-2" type="date" id="avail" name="available_by" value="<?= e($f['available_by'] ?? '') ?>">
        <fieldset class="mb-2"><legend class="form-label small">Amenities</legend>
          <?php foreach (['wifi', 'ac', 'washing_machine', 'power_backup', 'meals', 'attached_bath', 'security', 'wheelchair'] as $a): ?>
            <div class="form-check small"><input class="form-check-input" type="checkbox" name="amenities[]" value="<?= e($a) ?>" id="am_<?= e($a) ?>"<?= checked(in_array($a, $f['amenities'] ?? [], true)) ?>><label class="form-check-label" for="am_<?= e($a) ?>"><?= e(Options::AMENITIES[$a]) ?></label></div>
          <?php endforeach; ?>
        </fieldset>
        <label class="form-label small" for="sort">Sort</label>
        <select class="form-select form-select-sm mb-3" id="sort" name="sort"><?php foreach (ListingService::SORTS as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($f['sort'] ?? 'newest', $k) ?>><?= e($v) ?></option><?php endforeach; ?></select>
        <div class="d-flex gap-2"><button class="btn btn-primary btn-sm flex-fill">Show results</button><a class="btn btn-light btn-sm" href="<?= e(url('/rooms')) ?>">Reset</a></div>
      </form>
      </div>
    </div>
  </aside>
  <div class="col-lg-9">
    <?php if (!$rows): ?>
      <div class="surface"><?= \Flatmates\Http\View::partial('partials/empty', ['icon' => 'search', 'title' => 'No rooms match these filters yet', 'text' => 'Try a wider budget or another locality — or create a profile to get matched with students who already have a place.', 'cta' => empty($currentUser) ? ['Find My Flatmate', '/register'] : ['Clear filters', '/rooms']]) ?></div>
    <?php else: ?>
      <div class="row g-3 g-lg-4"><?php foreach ($rows as $l): ?><div class="col-sm-6 col-xl-4"><?= \Flatmates\Http\View::partial('partials/listing_card', ['l' => $l]) ?></div><?php endforeach; ?></div>
      <?= \Flatmates\Http\View::partial('partials/pager', ['pager' => $pager]) ?>
    <?php endif; ?>
  </div>
</div>
