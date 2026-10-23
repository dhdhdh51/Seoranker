<?php $isAdmin = $currentUser->isSuperAdmin(); ?>
<div class="page-head">
  <div><h1 class="h3 mb-0"><?= e($client['name']) ?></h1><div class="text-body-secondary small"><?= e($client['company']) ?> <?= badge($client['status']) ?></div></div>
  <div class="d-flex gap-2">
    <?php if ($isAdmin): ?><a class="btn btn-outline-secondary" href="<?= e(url('/clients/' . $client['id'] . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a><?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url('/projects/new', ['client_id' => $client['id']])) ?>"><i class="bi bi-plus-lg"></i> New project</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card card-soft h-100">
      <div class="card-header bg-transparent fw-semibold">Projects</div>
      <?php if (!$projects): ?>
        <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'kanban', 'heading' => 'No projects for this client']) ?>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php foreach ($projects as $p): ?>
          <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= e(url('/projects/' . $p['id'])) ?>">
            <span><span class="fw-semibold"><?= e($p['name']) ?></span><br><span class="small text-body-secondary"><?= e($p['domain']) ?></span></span>
            <?= badge($p['status']) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($isAdmin): ?>
  <div class="col-lg-5">
    <div class="card card-soft">
      <div class="card-header bg-transparent fw-semibold">Client portal users</div>
      <div class="card-body">
        <p class="small text-body-secondary">Client users can view only this client’s projects. “Approve” lets them approve outreach where the project policy requires client approval.</p>
        <?php if ($users): ?>
          <ul class="list-unstyled small">
          <?php foreach ($users as $cu): ?>
            <li class="d-flex justify-content-between align-items-center border-bottom py-2">
              <span><?= e($cu['name']) ?> <span class="text-body-secondary">&lt;<?= e($cu['email']) ?>&gt;</span><br>
                <?= $cu['can_approve'] ? badge('approved', 'can approve') : '' ?> <?= $cu['can_export'] ? badge('info', 'can export') : '' ?></span>
              <form method="post" action="<?= e(url('/clients/' . $client['id'] . '/users/' . $cu['user_id'] . '/remove')) ?>" data-confirm="Remove this user's access to the client?"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-x-lg"></i></button></form>
            </li>
          <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($clientUserOptions): ?>
        <form method="post" action="<?= e(url('/clients/' . $client['id'] . '/users')) ?>" class="row g-2 mt-1">
          <?= csrf_field() ?>
          <div class="col-12"><select name="user_id" class="form-select form-select-sm" required><option value="">Select a Client-role user…</option><?php foreach ($clientUserOptions as $id => $label): ?><option value="<?= (int)$id ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_approve" value="1" id="ca"><label class="form-check-label small" for="ca">Can approve</label></div></div>
          <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="can_export" value="1" id="ce" checked><label class="form-check-label small" for="ce">Can export</label></div></div>
          <div class="col-12"><button class="btn btn-sm btn-primary">Grant access</button></div>
        </form>
        <?php else: ?>
          <p class="small mb-0">Create a user with the <strong>Client</strong> role in <a href="<?= e(url('/users/new')) ?>">Users &amp; roles</a> first.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
