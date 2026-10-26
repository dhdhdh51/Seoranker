<?php

use Flatmates\Services\ProfileService;
use Flatmates\Support\Options;

/** @var array $p */
$val = static fn(string $f) => old($f, $p[$f] ?? '');
$select = static function (string $field, string $group, string $labelText, bool $required = false, string $help = '') use ($val, $errors): string {
    $h = '<div class="mb-3"><label class="form-label" for="f_' . e($field) . '">' . e($labelText) . ($required ? '' : ' <span class="text-body-secondary small">(optional)</span>') . '</label>';
    $h .= '<select class="form-select' . invalid($errors, $field) . '" id="f_' . e($field) . '" name="' . e($field) . '"' . ($required ? ' required' : '') . '><option value="">Choose…</option>';
    foreach (Options::GROUPS[$group] as $k => $v) {
        $h .= '<option value="' . e($k) . '"' . selected($val($field), $k) . '>' . e($v) . '</option>';
    }
    return $h . '</select>' . field_error($errors, $field) . ($help ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div>';
};
$vis = json_decode((string)($p['field_visibility'] ?? ''), true) ?: [];
$pct = (int)round(($step - 1) / 4 * 100);
?>
<div class="row justify-content-center"><div class="col-lg-7">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h4 mb-0"><?= $editing ? 'Edit profile' : 'Set up your profile' ?></h1>
    <span class="small text-body-secondary">Step <?= (int)$step ?> of 4</span>
  </div>
  <div class="progress mb-3" role="progressbar" aria-label="Profile progress" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" style="height:6px"><div class="progress-bar" style="width:<?= max(5, $pct) ?>%"></div></div>
  <ul class="nav nav-pills nav-fill small mb-4 step-pills">
    <?php foreach (ProfileService::STEPS as $n => $name): $reach = $editing || $n <= max(1, (int)$p['onboarding_step']); ?>
      <li class="nav-item"><a class="nav-link<?= $n === $step ? ' active' : '' ?><?= $reach ? '' : ' disabled' ?>" href="<?= e(url('/onboarding/' . $n)) ?>"><?= e($name) ?></a></li>
    <?php endforeach; ?>
  </ul>

  <form method="post" action="<?= e(url('/onboarding/' . $step)) ?>" enctype="multipart/form-data" class="card card-body shadow-sm border-0">
    <?= csrf_field() ?>
    <?php if ($step === 1): ?>
      <div class="mb-3"><label class="form-label" for="display_name">First name or nickname</label>
        <input class="form-control<?= invalid($errors, 'display_name') ?>" id="display_name" name="display_name" value="<?= e($val('display_name')) ?>" maxlength="60" required>
        <?= field_error($errors, 'display_name') ?><div class="form-text">Shown to other students. Your full name stays private.</div></div>
      <?= $select('accommodation_status', 'accommodation_status', 'What are you looking for?', true) ?>
      <?= $select('gender', 'gender', 'Gender', false, 'Optional. Hidden on your profile by default — used only if you or others want same-gender flatmates.') ?>
      <?= $select('flatmate_gender_pref', 'flatmate_gender_pref', 'Flatmate preference', true) ?>
      <div class="mb-3"><label class="form-label" for="bio">About you <span class="text-body-secondary small">(optional)</span></label>
        <textarea class="form-control<?= invalid($errors, 'bio') ?>" id="bio" name="bio" rows="4" maxlength="1000" placeholder="Course, hobbies, what kind of flatmate you are…"><?= e($val('bio')) ?></textarea><?= field_error($errors, 'bio') ?>
        <div class="form-text">Don't share phone numbers or addresses here — use in-app chat.</div></div>
      <fieldset class="mb-3"><legend class="form-label fs-6">Languages you speak <span class="text-body-secondary small">(optional)</span></legend>
        <div class="d-flex flex-wrap gap-2">
          <?php $langs = is_array(old('languages', null)) ? old('languages') : explode(',', (string)($p['languages'] ?? '')); foreach (Options::LANGUAGES as $lang): $id = 'lang_' . md5($lang); ?>
            <input type="checkbox" class="btn-check" name="languages[]" value="<?= e($lang) ?>" id="<?= $id ?>"<?= checked(in_array($lang, $langs, true)) ?>><label class="btn btn-sm btn-outline-secondary rounded-pill" for="<?= $id ?>"><?= e($lang) ?></label>
          <?php endforeach; ?>
        </div></fieldset>
    <?php elseif ($step === 2): ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="city_id">City</label>
          <select class="form-select<?= invalid($errors, 'city_id') ?>" id="city_id" name="city_id" required data-city-select="#college_id">
            <option value="">Choose…</option><?php foreach ($cities as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($val('city_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select><?= field_error($errors, 'city_id') ?></div>
        <div class="col-md-6"><label class="form-label" for="college_id">College <span class="text-body-secondary small">(optional)</span></label>
          <select class="form-select" id="college_id" name="college_id">
            <option value="">Not listed / prefer not to say</option><?php foreach ($colleges as $c): ?><option value="<?= (int)$c['id'] ?>" data-city="<?= (int)$c['city_id'] ?>"<?= selected($val('college_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-8"><label class="form-label" for="course">Course <span class="text-body-secondary small">(optional)</span></label><input class="form-control" id="course" name="course" value="<?= e($val('course')) ?>" maxlength="120" placeholder="e.g. B.Tech CSE"></div>
        <div class="col-md-4"><label class="form-label" for="academic_year">Year</label><select class="form-select" id="academic_year" name="academic_year"><option value="">—</option><?php for ($y = 1; $y <= 6; $y++): ?><option value="<?= $y ?>"<?= selected($val('academic_year'), $y) ?>>Year <?= $y ?></option><?php endfor; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="locality">Preferred area <span class="text-body-secondary small">(optional)</span></label><input class="form-control" id="locality" name="locality" value="<?= e($val('locality')) ?>" maxlength="120" placeholder="e.g. Sector 62"></div>
        <div class="col-md-6"><label class="form-label" for="move_in_date">Move-in date</label><input class="form-control" type="date" id="move_in_date" name="move_in_date" value="<?= e($val('move_in_date')) ?>"></div>
        <div class="col-6"><label class="form-label" for="budget_min">Budget from (₹/month)</label><input class="form-control" type="number" inputmode="numeric" min="0" step="500" id="budget_min" name="budget_min" value="<?= e($val('budget_min')) ?>"></div>
        <div class="col-6"><label class="form-label" for="budget_max">Budget up to (₹/month)</label><input class="form-control<?= invalid($errors, 'budget_max') ?>" type="number" inputmode="numeric" min="1000" step="500" id="budget_max" name="budget_max" value="<?= e($val('budget_max')) ?>" required><?= field_error($errors, 'budget_max') ?></div>
        <div class="col-md-4"><label class="form-label" for="flatmates_count">Flatmates wanted</label><select class="form-select" id="flatmates_count" name="flatmates_count"><option value="">Any</option><?php for ($n = 1; $n <= 5; $n++): ?><option value="<?= $n ?>"<?= selected($val('flatmates_count'), $n) ?>><?= $n ?></option><?php endfor; ?></select></div>
        <div class="col-md-4"><?= $select('room_type', 'room_type', 'Room type') ?></div>
        <div class="col-md-4"><?= $select('ac_pref', 'ac_pref', 'AC') ?></div>
        <div class="col-12"><label class="form-label" for="accessibility">Accessibility needs <span class="text-body-secondary small">(optional, private)</span></label><input class="form-control" id="accessibility" name="accessibility" value="<?= e($val('accessibility')) ?>" maxlength="255" placeholder="e.g. ground floor or lift needed"></div>
      </div>
    <?php elseif ($step === 3): ?>
      <p class="text-body-secondary small">These power your compatibility score. Answer honestly — mismatched habits are the #1 cause of flatmate conflicts.</p>
      <div class="row g-x-3">
        <div class="col-md-6"><?= $select('cleanliness', 'cleanliness', 'How tidy are you?') ?></div>
        <div class="col-md-6"><?= $select('sleep_schedule', 'sleep_schedule', 'Sleep schedule') ?></div>
        <div class="col-md-6"><?= $select('study_habits', 'study_habits', 'Study style') ?></div>
        <div class="col-md-6"><?= $select('social_level', 'social_level', 'Social style') ?></div>
        <div class="col-md-6"><?= $select('guests', 'guests', 'Guests') ?></div>
        <div class="col-md-6"><?= $select('diet', 'diet', 'Food habits') ?></div>
        <div class="col-md-6"><?= $select('diet_pref', 'diet_pref', 'Kitchen preference') ?></div>
        <div class="col-md-6"><?= $select('smoking', 'smoking', 'Smoking') ?></div>
        <div class="col-md-6"><?= $select('alcohol', 'alcohol', 'Drinking') ?></div>
      </div>
      <input type="hidden" name="smoking_ok" value="0"><input type="hidden" name="alcohol_ok" value="0">
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="smoking_ok" value="1" id="smoking_ok"<?= checked(($p['smoking_ok'] ?? 1) !== 0 && ($p['smoking_ok'] ?? 1) !== '0') ?>><label class="form-check-label" for="smoking_ok">I'm OK living with someone who smokes</label></div>
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="alcohol_ok" value="1" id="alcohol_ok"<?= checked(($p['alcohol_ok'] ?? 1) !== 0 && ($p['alcohol_ok'] ?? 1) !== '0') ?>><label class="form-check-label" for="alcohol_ok">I'm OK with a flatmate who drinks</label></div>
    <?php else: ?>
      <div class="d-flex align-items-center gap-3 mb-3">
        <?= avatar($p['photo_path'], (string)$p['display_name'], 'xl') ?>
        <div class="flex-grow-1"><label class="form-label" for="photo">Profile photo <span class="text-body-secondary small">(recommended)</span></label>
          <input class="form-control<?= invalid($errors, 'photo') ?>" type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"><?= field_error($errors, 'photo') ?>
          <div class="form-text">JPG/PNG/WebP up to 6 MB. Location data (EXIF) is removed automatically. Only signed-in members can see it.</div></div>
      </div>
      <hr>
      <h2 class="h6">Privacy</h2>
      <input type="hidden" name="discoverable" value="0">
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="discoverable" value="1" id="discoverable"<?= checked((int)$p['discoverable'] === 1) ?>><label class="form-check-label" for="discoverable"><strong>Show me in Discover</strong><br><span class="small text-body-secondary">Turn off to pause — existing matches and chats continue.</span></label></div>
      <label class="form-label" for="visibility">Who can open my full profile?</label>
      <select class="form-select mb-3" id="visibility" name="visibility">
        <option value="members"<?= selected($p['visibility'], 'members') ?>>Any verified-email member</option>
        <option value="matches"<?= selected($p['visibility'], 'matches') ?>>Only my matches</option>
      </select>
      <fieldset><legend class="form-label fs-6">Show these on my profile</legend>
        <?php foreach (Options::PRIVATE_FIELDS as $f => $lbl): $on = array_key_exists($f, $vis) ? (bool)$vis[$f] : !in_array($f, Options::HIDDEN_BY_DEFAULT, true); ?>
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="show_<?= e($f) ?>" value="1" id="show_<?= e($f) ?>"<?= checked($on) ?>><label class="form-check-label" for="show_<?= e($f) ?>"><?= e($lbl) ?></label></div>
        <?php endforeach; ?>
        <div class="form-text">Your email, phone, full name, date of birth and documents are never shown to students.</div>
      </fieldset>
    <?php endif; ?>
    <div class="d-flex justify-content-between gap-2 mt-4">
      <?php if ($step > 1): ?><a class="btn btn-light" href="<?= e(url('/onboarding/' . ($step - 1))) ?>"><i class="bi bi-arrow-left"></i> Back</a><?php else: ?><span></span><?php endif; ?>
      <div class="d-flex gap-2">
        <?php if ($editing): ?><button class="btn btn-outline-primary" name="save_only" value="1">Save</button><?php endif; ?>
        <button class="btn btn-primary"><?= $step < 4 ? 'Continue' : ($editing ? 'Save & discover' : 'Finish & start matching') ?> <i class="bi bi-arrow-right"></i></button>
      </div>
    </div>
  </form>
</div></div>
