<h1 class="h3 mb-1">Student flatmates by city</h1>
<p class="text-body-secondary mb-4">Pick your city or college to see rooms, vacant beds and students looking for flatmates nearby.</p>
<div class="row g-3">
<?php foreach ($cities as $c): ?>
  <div class="col-md-6 col-lg-4"><div class="card h-100 border-0 shadow-sm"><div class="card-body">
    <h2 class="h5"><a href="<?= e(url('/flatmates/' . $c['slug'])) ?>">Flatmates in <?= e($c['name']) ?></a></h2>
    <div class="small text-body-secondary mb-2"><?= e($c['state']) ?></div>
    <ul class="small mb-0 list-unstyled"><?php foreach ($colleges[(int)$c['id']] ?? [] as $co): ?><li><a href="<?= e(url('/flatmates/' . $c['slug'] . '/' . $co['slug'])) ?>">Near <?= e($co['name']) ?></a></li><?php endforeach; ?></ul>
  </div></div></div>
<?php endforeach; ?>
</div>
