<div class="page-head">
  <h1 class="h3 mb-0">Projects</h1>
  <?php if ($currentUser->isStaff()): ?><a class="btn btn-primary" href="<?= e(url('/projects/new')) ?>"><i class="bi bi-plus-lg"></i> Add project</a><?php endif; ?>
</div>
<form class="card card-soft mb-3" method="get"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label small mb-0" for="q">Search</label><input id="q" class="form-control form-control-sm" name="q" value="<?= e($filters['q']) ?>" placeholder="Name or domain"></div>
  <div><label class="form-label small mb-0" for="st">Status</label><select id="st" class="form-select form-select-sm" name="status"><option value="">Any</option><?php foreach (['active', 'paused', 'archived'] as $s): ?><option value="<?= $s ?>"<?= selected($filters['status'], $s) ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-sm btn-outline-primary">Filter</button>
</div></form>
<div class="card card-soft">
<?php if (!$rows): ?>
  <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'kanban', 'heading' => 'No projects found', 'text' => 'Projects hold a website, keywords, target pages and outreach identity.', 'actionUrl' => $currentUser->isStaff() ? url('/projects/new') : null, 'actionLabel' => 'Add project']) ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Project</th><th>Client</th><th>Domain</th><th>Ownership</th><th class="text-end">Prospects</th><th class="text-end">Active links</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td><a class="fw-semibold" href="<?= e(url('/projects/' . $p['id'])) ?>"><?= e($p['name']) ?></a></td>
        <td><?= e($p['client_name']) ?></td>
        <td class="small"><?= e($p['domain']) ?></td>
        <td><?= $p['verified_at'] ? badge('verified', 'Verified') : badge('unverified', 'Not verified') ?></td>
        <td class="text-end"><?= num((int)$p['prospect_count']) ?></td>
        <td class="text-end"><?= num((int)$p['active_links']) ?></td>
        <td><?= badge($p['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-footer bg-transparent"><?= \BharatSeo\Http\View::partial('partials/pagination', ['pager' => $pager]) ?></div>
<?php endif; ?>
</div>
