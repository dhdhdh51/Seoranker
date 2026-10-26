<?php $max = max(1, ...array_map(static fn($d) => max($d['signups'], $d['matches'], $d['listings']), $a['daily'])); ?>
<h1 class="h4 mb-3">Analytics <small class="text-body-secondary fs-6">last 30 days</small></h1>
<div class="row g-3 mb-3">
  <?php foreach ($a['funnel'] as $k => $v): ?><div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-body-secondary"><?= e(ucfirst(str_replace('_', ' ', $k))) ?></div><div class="fs-4 fw-bold"><?= (int)$v ?></div></div></div></div><?php endforeach; ?>
</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
  <div class="small mb-2"><span class="legend l1"></span> Sign-ups <span class="legend l2 ms-2"></span> Matches <span class="legend l3 ms-2"></span> Listings</div>
  <div class="bars" role="img" aria-label="Daily sign-ups, matches and listings">
    <?php foreach ($a['daily'] as $d): ?><div class="bar-day" title="<?= e($d['date']) ?>: <?= (int)$d['signups'] ?> sign-ups, <?= (int)$d['matches'] ?> matches, <?= (int)$d['listings'] ?> listings, <?= (int)$d['messages'] ?> messages">
      <span class="b1" style="height:<?= round($d['signups'] / $max * 100) ?>%"></span><span class="b2" style="height:<?= round($d['matches'] / $max * 100) ?>%"></span><span class="b3" style="height:<?= round($d['listings'] / $max * 100) ?>%"></span></div><?php endforeach; ?>
  </div>
</div></div>
<div class="row g-3">
  <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Top cities</div><ul class="list-group list-group-flush small"><?php foreach ($a['top_cities'] as $r): ?><li class="list-group-item d-flex justify-content-between"><?= e($r['name']) ?><span><?= (int)$r['n'] ?></span></li><?php endforeach; ?></ul></div></div>
  <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Top colleges</div><ul class="list-group list-group-flush small"><?php foreach ($a['top_colleges'] as $r): ?><li class="list-group-item d-flex justify-content-between"><?= e($r['name']) ?><span><?= (int)$r['n'] ?></span></li><?php endforeach; ?></ul></div></div>
</div>
