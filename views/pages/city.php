<?php \Flatmates\Http\View::share('jsonLd', ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => absolute_url('/')],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Cities', 'item' => absolute_url('/cities')],
    ['@type' => 'ListItem', 'position' => 3, 'name' => $c['name'], 'item' => absolute_url('/flatmates/' . $c['slug'])]]]); ?>
<nav aria-label="breadcrumb" class="small"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url('/')) ?>">Home</a></li><li class="breadcrumb-item"><a href="<?= e(url('/cities')) ?>">Cities</a></li><li class="breadcrumb-item active" aria-current="page"><?= e($c['name']) ?></li></ol></nav>
<header class="mb-4">
  <h1 class="h2">Student flatmates & rooms in <?= e($c['name']) ?></h1>
  <p class="lead text-body-secondary">Find a compatible flatmate, a shared room or a vacant bed near your college in <?= e($c['name']) ?>, <?= e($c['state']) ?> — matched on budget and lifestyle, with private in-app chat.</p>
  <div class="d-flex flex-wrap gap-3 small">
    <span class="chip"><i class="bi bi-people"></i> <?= (int)$stats['students'] ?> students looking</span>
    <span class="chip"><i class="bi bi-house"></i> <?= (int)$stats['listings'] ?> live listings</span>
    <?php if ($stats['avg_rent']): ?><span class="chip"><i class="bi bi-cash"></i> Avg. rent <?= e(inr($stats['avg_rent'])) ?>/mo</span><?php endif; ?>
  </div>
  <div class="d-flex gap-2 mt-3"><a class="btn btn-primary" href="<?= e(url('/register')) ?>">Find flatmates in <?= e($c['name']) ?></a><a class="btn btn-outline-primary" href="<?= e(url('/rooms', ['city_id' => $c['id']])) ?>">All rooms in <?= e($c['name']) ?></a></div>
</header>
<?php if ($c['intro']): ?><div class="mb-4 text-pre"><?= e($c['intro']) ?></div><?php endif; ?>
<?php if ($colleges): ?>
<section class="mb-4"><h2 class="h5">Colleges in <?= e($c['name']) ?></h2>
  <div class="d-flex flex-wrap gap-2"><?php foreach ($colleges as $co): ?><a class="btn btn-sm btn-outline-secondary rounded-pill" href="<?= e(url('/flatmates/' . $c['slug'] . '/' . $co['slug'])) ?>">Near <?= e($co['name']) ?></a><?php endforeach; ?></div></section>
<?php endif; ?>
<section class="mb-4"><h2 class="h5">Latest listings in <?= e($c['name']) ?></h2>
<?php if ($listings): ?><div class="row g-3"><?php foreach ($listings as $l): ?><div class="col-sm-6 col-lg-4"><?= \Flatmates\Http\View::partial('partials/listing_card', ['l' => $l]) ?></div><?php endforeach; ?></div>
<?php else: ?><p class="text-body-secondary">No public listings in <?= e($c['name']) ?> yet. Have a vacant bed? <a href="<?= e(url('/register')) ?>">List it free</a>.</p><?php endif; ?></section>
<section class="card border-0 shadow-sm"><div class="card-body small">
  <h2 class="h6">Tips for renting as a student in <?= e($c['name']) ?></h2>
  <ul class="mb-0"><li>Visit in person before paying any token amount or deposit.</li><li>Ask for a written rent agreement and receipts for every payment.</li><li>Check the commute to campus at the time you'd actually travel.</li><li>Agree house rules (guests, cleaning rota, bills split) with flatmates up front.</li></ul>
</div></section>
