<div class="page-head">
  <h1 class="h3 mb-0">Clients</h1>
  <?php if ($currentUser->isSuperAdmin()): ?><a class="btn btn-primary" href="<?= e(url('/clients/new')) ?>"><i class="bi bi-plus-lg"></i> New client</a><?php endif; ?>
</div>
<div class="card card-soft">
  <?php if (!$clients): ?>
    <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'buildings', 'heading' => 'No clients yet', 'text' => 'Clients own projects. Client users only ever see their own projects.', 'actionUrl' => $currentUser->isSuperAdmin() ? url('/clients/new') : null, 'actionLabel' => 'Create client']) ?>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Name</th><th>Company</th><th>Contact</th><th class="text-end">Projects</th><th class="text-end">Users</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td><a href="<?= e(url('/clients/' . $c['id'])) ?>" class="fw-semibold"><?= e($c['name']) ?></a></td>
          <td><?= e($c['company']) ?></td>
          <td class="small"><?= e($c['contact_email']) ?></td>
          <td class="text-end"><?= num((int)$c['project_count']) ?></td>
          <td class="text-end"><?= num((int)$c['user_count']) ?></td>
          <td><?= badge($c['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
