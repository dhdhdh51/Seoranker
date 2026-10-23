<div class="page-head"><div><h1 class="h3 mb-0">Suppression list</h1><div class="small text-body-secondary"><?= num($globalCount) ?> global entries. Unsubscribes are permanent and apply to every project.</div></div></div>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card card-soft"><div class="card-body">
      <h2 class="h6">Add suppression</h2>
      <form method="post" action="<?= e(url('/outreach/suppressions')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><input name="email" class="form-control" required placeholder="name@example.com or *@example.com"></div>
        <div class="col-12"><select name="project_id" class="form-select"><?php if ($currentUser->isSuperAdmin()): ?><option value="">Global (all projects)</option><?php endif; ?><?php foreach ($projects as $id => $l): ?><option value="<?= (int)$id ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><input name="note" class="form-control" placeholder="Note (optional)" maxlength="255"></div>
        <div class="col-12"><button class="btn btn-primary">Suppress</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card card-soft">
      <?php if (!$rows): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'slash-circle', 'heading' => 'No suppressed addresses']) ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Email</th><th>Scope</th><th>Reason</th><th>Note</th><th>Added</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $s): ?><tr><td class="small text-break"><?= e($s['email']) ?></td><td class="small"><?= e($s['project_name'] ?? 'Global') ?></td><td><?= badge($s['reason'] === 'unsubscribe' ? 'suppressed' : 'info', $s['reason']) ?></td><td class="small"><?= e($s['note']) ?></td><td class="small text-nowrap"><?= e(fmt_date($s['created_at'])) ?></td>
          <td><?php if ($currentUser->isSuperAdmin() && $s['reason'] !== 'unsubscribe'): ?><form method="post" action="<?= e(url('/outreach/suppressions/' . $s['id'] . '/remove')) ?>" data-confirm="Remove this suppression?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
</div>
