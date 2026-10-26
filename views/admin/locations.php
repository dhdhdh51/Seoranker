<h1 class="h4 mb-3">Cities & colleges</h1>
<p class="small text-body-secondary">Each college email domain enables instant college-email verification for that college. Only add official domains (e.g. <code>amity.edu</code>) — never public providers like gmail.com.</p>
<div class="row g-3">
  <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body">
    <h2 class="h6">Add city</h2>
    <form method="post" action="<?= e(url('/admin/locations')) ?>" class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="kind" value="city"><input type="hidden" name="active" value="1">
      <input class="form-control form-control-sm" name="name" placeholder="City" required aria-label="City"><input class="form-control form-control-sm" name="state" placeholder="State" required aria-label="State">
      <textarea class="form-control form-control-sm" name="intro" rows="2" placeholder="Intro text for the city SEO page (optional)" aria-label="Intro"></textarea><button class="btn btn-sm btn-primary">Add city</button></form>
    <hr>
    <h2 class="h6">Add college</h2>
    <form method="post" action="<?= e(url('/admin/locations')) ?>" class="vstack gap-2"><?= csrf_field() ?><input type="hidden" name="kind" value="college"><input type="hidden" name="active" value="1">
      <input class="form-control form-control-sm" name="name" placeholder="College name" required aria-label="College name">
      <select class="form-select form-select-sm" name="city_id" required aria-label="City"><option value="">City…</option><?php foreach ($cities as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
      <input class="form-control form-control-sm<?= invalid($errors, 'email_domain') ?>" name="email_domain" placeholder="Email domain, e.g. college.edu.in" aria-label="Email domain"><?= field_error($errors, 'email_domain') ?>
      <button class="btn btn-sm btn-primary">Add college</button></form>
  </div></div></div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-header bg-transparent fw-semibold">Cities</div><ul class="list-group list-group-flush small">
      <?php foreach ($cities as $c): ?><li class="list-group-item"><form method="post" action="<?= e(url('/admin/locations')) ?>" class="d-flex gap-2 align-items-center flex-wrap"><?= csrf_field() ?><input type="hidden" name="kind" value="city"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <input class="form-control form-control-sm w-auto" name="name" value="<?= e($c['name']) ?>" aria-label="Name"><input class="form-control form-control-sm w-auto" name="state" value="<?= e($c['state']) ?>" aria-label="State">
        <input type="hidden" name="intro" value="<?= e($c['intro'] ?? '') ?>">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="ca<?= (int)$c['id'] ?>"<?= checked((int)$c['active']) ?>><label class="form-check-label" for="ca<?= (int)$c['id'] ?>">Active</label></div>
        <a href="<?= e(url('/flatmates/' . $c['slug'])) ?>" target="_blank">/<?= e($c['slug']) ?></a><button class="btn btn-sm btn-light ms-auto">Save</button></form></li><?php endforeach; ?></ul></div>
    <div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Colleges</div><ul class="list-group list-group-flush small">
      <?php foreach ($colleges as $co): ?><li class="list-group-item"><form method="post" action="<?= e(url('/admin/locations')) ?>" class="d-flex gap-2 align-items-center flex-wrap"><?= csrf_field() ?><input type="hidden" name="kind" value="college"><input type="hidden" name="id" value="<?= (int)$co['id'] ?>"><input type="hidden" name="city_id" value="<?= (int)$co['city_id'] ?>">
        <input class="form-control form-control-sm" style="max-width:16rem" name="name" value="<?= e($co['name']) ?>" aria-label="Name"><span class="text-body-secondary"><?= e($co['city_name']) ?></span>
        <input class="form-control form-control-sm" style="max-width:12rem" name="email_domain" value="<?= e($co['email_domain'] ?? '') ?>" placeholder="domain" aria-label="Email domain">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="1" id="coa<?= (int)$co['id'] ?>"<?= checked((int)$co['active']) ?>><label class="form-check-label" for="coa<?= (int)$co['id'] ?>">Active</label></div>
        <button class="btn btn-sm btn-light ms-auto">Save</button></form></li><?php endforeach; ?></ul></div>
  </div>
</div>
