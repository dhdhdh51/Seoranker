<?php
/** @var array $profile */
$p = $profile;
$row = static function (string $icon, string $labelText, ?string $value): string {
    return $value === null || $value === '' || $value === '—' ? '' : '<li class="list-group-item d-flex gap-3"><i class="bi bi-' . e($icon) . ' text-primary"></i><div><div class="small text-body-secondary">' . e($labelText) . '</div>' . e($value) . '</div></li>';
};
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card shadow-sm border-0 text-center p-4">
      <div class="mx-auto mb-3"><?= avatar($p['photo_path'], $p['display_name'], 'xxl') ?></div>
      <h1 class="h4 mb-1"><?= e($p['display_name']) ?><?= isset($p['age']) ? ', ' . (int)$p['age'] : '' ?>
        <?php if ($p['verified']): ?><i class="bi bi-patch-check-fill text-primary" title="Verified student"></i><?php endif; ?></h1>
      <div class="text-body-secondary small"><?= e(implode(' · ', array_filter([$p['college_name'] ?? null, $p['city_name'] ?? null]))) ?></div>
      <div class="mt-2"><span class="badge text-bg-primary-subtle text-primary-emphasis"><?= e(label('accommodation_status', $p['accommodation_status'])) ?></span></div>
      <?php if (!empty($compat)): ?>
        <div class="compat-ring mx-auto mt-3" style="--pct:<?= (int)$compat['score'] ?>"><span><?= (int)$compat['score'] ?>%</span></div>
        <div class="small text-body-secondary mt-1">compatibility</div>
      <?php endif; ?>
      <?php if ($self): ?>
        <div class="mt-3"><div class="small mb-1">Profile <?= (int)$p['completeness'] ?>% complete</div><div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= (int)$p['completeness'] ?>%"></div></div></div>
        <div class="d-grid gap-2 mt-3">
          <a class="btn btn-primary" href="<?= e(url('/onboarding/1')) ?>"><i class="bi bi-pencil me-1"></i>Edit profile</a>
          <a class="btn btn-outline-secondary" href="<?= e(url('/onboarding/4')) ?>"><i class="bi bi-shield-lock me-1"></i>Photo & privacy</a>
          <?php if (!$p['verified']): ?><a class="btn btn-outline-primary" href="<?= e(url('/verify')) ?>"><i class="bi bi-patch-check me-1"></i>Get verified</a><?php endif; ?>
        </div>
        <?php if (empty($raw['discoverable'])): ?><div class="alert alert-secondary small mt-3 mb-0">You are hidden from Discover.</div><?php endif; ?>
      <?php else: ?>
        <div class="d-grid gap-2 mt-3">
          <?php if (!empty($conversationId)): ?><a class="btn btn-primary" href="<?= e(url('/chat/' . $conversationId)) ?>"><i class="bi bi-chat-dots me-1"></i>Message</a><?php endif; ?>
          <button class="btn btn-outline-secondary" type="button" data-save="profile" data-id="<?= (int)$p['user_id'] ?>" aria-pressed="<?= !empty($saved) ? 'true' : 'false' ?>"><i class="bi bi-bookmark<?= !empty($saved) ? '-fill' : '' ?> me-1"></i><span><?= !empty($saved) ? 'Saved' : 'Save' ?></span></button>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-link text-danger flex-fill" type="button" data-report="user" data-id="<?= (int)$p['user_id'] ?>"><i class="bi bi-flag"></i> Report</button>
            <form method="post" action="<?= e(url('/block/' . (int)$p['user_id'])) ?>" class="flex-fill" data-confirm="Block <?= e($p['display_name']) ?>? You won't see each other anymore."><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger w-100"><i class="bi bi-slash-circle"></i> Block</button></form>
          </div>
          <?php if (!empty($match)): ?><form method="post" action="<?= e(url('/matches/' . (int)$match['id'] . '/unmatch')) ?>" data-confirm="Unmatch? Your chat will close."><?= csrf_field() ?><button class="btn btn-sm btn-link text-body-secondary">Unmatch</button></form><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-8">
    <?php if (!empty($compat) && ($compat['reasons'] || $compat['conflicts'])): ?>
      <div class="card shadow-sm border-0 mb-3"><div class="card-body">
        <h2 class="h6">Why you might click</h2>
        <div class="d-flex flex-wrap gap-2"><?php foreach ($compat['reasons'] as $r): ?><span class="chip chip-ok"><i class="bi bi-check2"></i> <?= e($r) ?></span><?php endforeach; ?>
        <?php foreach ($compat['conflicts'] as $r): ?><span class="chip chip-warn"><i class="bi bi-exclamation-circle"></i> <?= e($r) ?></span><?php endforeach; ?></div>
      </div></div>
    <?php endif; ?>
    <?php if ($p['bio']): ?><div class="card shadow-sm border-0 mb-3"><div class="card-body"><h2 class="h6">About</h2><p class="mb-0 text-pre"><?= e($p['bio']) ?></p></div></div><?php endif; ?>
    <div class="row g-3">
      <div class="col-md-6"><div class="card shadow-sm border-0 h-100"><div class="card-header bg-transparent fw-semibold">Housing</div><ul class="list-group list-group-flush">
        <?= $row('cash-coin', 'Budget', $p['budget_max'] ? ($p['budget_min'] ? inr($p['budget_min']) . ' – ' : 'Up to ') . inr($p['budget_max']) . '/month' : null) ?>
        <?= $row('calendar-event', 'Move-in', $p['move_in_date'] ? fmt_date($p['move_in_date']) : null) ?>
        <?= $row('geo-alt', 'Preferred area', $p['locality']) ?>
        <?= $row('door-open', 'Room type', label('room_type', $p['room_type'])) ?>
        <?= $row('people', 'Flatmates wanted', $p['flatmates_count'] ? (string)$p['flatmates_count'] : null) ?>
        <?= $row('snow', 'AC', label('ac_pref', $p['ac_pref'])) ?>
      </ul></div></div>
      <div class="col-md-6"><div class="card shadow-sm border-0 h-100"><div class="card-header bg-transparent fw-semibold">Lifestyle</div><ul class="list-group list-group-flush">
        <?= $row('stars', 'Tidiness', $p['cleanliness'] ? label('cleanliness', (string)$p['cleanliness']) : null) ?>
        <?= $row('moon', 'Sleep', label('sleep_schedule', $p['sleep_schedule'])) ?>
        <?= $row('book', 'Study', label('study_habits', $p['study_habits'])) ?>
        <?= $row('emoji-smile', 'Social', label('social_level', $p['social_level'])) ?>
        <?= $row('door-closed', 'Guests', label('guests', $p['guests'])) ?>
        <?= isset($p['diet']) ? $row('egg-fried', 'Food', label('diet', $p['diet'])) : '' ?>
        <?= $row('basket', 'Kitchen', label('diet_pref', $p['diet_pref'])) ?>
        <?= isset($p['smoking']) ? $row('wind', 'Smoking', label('smoking', $p['smoking'])) : '' ?>
        <?= isset($p['alcohol']) ? $row('cup-straw', 'Drinking', label('alcohol', $p['alcohol'])) : '' ?>
      </ul></div></div>
      <?php if (isset($p['course']) || isset($p['languages']) || isset($p['gender'])): ?>
      <div class="col-12"><div class="card shadow-sm border-0"><ul class="list-group list-group-flush">
        <?= isset($p['course']) ? $row('mortarboard', 'Course', trim(($p['course'] ?? '') . ($p['academic_year'] ? ' · Year ' . $p['academic_year'] : ''))) : '' ?>
        <?= isset($p['languages']) ? $row('translate', 'Languages', str_replace(',', ', ', (string)$p['languages'])) : '' ?>
        <?= isset($p['gender']) ? $row('person', 'Gender', label('gender', $p['gender'])) : '' ?>
      </ul></div></div>
      <?php endif; ?>
    </div>
    <?php if ($self && !empty($verifications)): ?>
      <div class="card shadow-sm border-0 mt-3"><div class="card-body small"><h2 class="h6">Verification</h2>
        <?php foreach ($verifications as $v): ?><div><?= e(ucfirst(str_replace('_', ' ', $v['method']))) ?>: <strong><?= e($v['status']) ?></strong> <span class="text-body-secondary"><?= e(ago($v['created_at'])) ?></span></div><?php endforeach; ?>
      </div></div>
    <?php endif; ?>
    <?php if ($self): ?><p class="small text-body-secondary mt-3"><i class="bi bi-eye me-1"></i>This is how other students see you (fields you hide are only visible to you).</p><?php endif; ?>
  </div>
</div>
