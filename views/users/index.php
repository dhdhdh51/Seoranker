<?php use BharatSeo\Security\User; ?>
<div class="page-head"><h1 class="h3 mb-0">Users &amp; roles</h1><a class="btn btn-primary" href="<?= e(url('/users/new')) ?>"><i class="bi bi-person-plus"></i> New user</a></div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card card-soft h-100"><div class="card-body small"><h2 class="h6">Super Admin</h2>Manages users, clients, projects, settings, modules, cron and integrations. Sees every project and the audit log.</div></div></div>
  <div class="col-md-4"><div class="card card-soft h-100"><div class="card-body small"><h2 class="h6">SEO Manager</h2>Works only on projects they are assigned to (manager or viewer). Discovers and evaluates opportunities, prepares outreach and approves as the agency.</div></div></div>
  <div class="col-md-4"><div class="card card-soft h-100"><div class="card-body small"><h2 class="h6">Client</h2>Sees only their own client’s projects. Can approve outreach (if permitted and required by policy) and export reports (if permitted).</div></div></div>
</div>
<div class="card card-soft"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Access</th><th>Status</th><th>Last sign-in</th></tr></thead>
  <tbody><?php foreach ($users as $u): ?><tr>
    <td><a class="fw-semibold" href="<?= e(url('/users/' . $u['id'] . '/edit')) ?>"><?= e($u['name']) ?></a></td>
    <td class="small"><?= e($u['email']) ?></td>
    <td><?= e(User::ROLES[$u['role']] ?? $u['role']) ?></td>
    <td class="small"><?= $u['role'] === 'client' ? e($u['clients'] ?: 'No client assigned') : ($u['role'] === 'seo_manager' ? num((int)$u['project_count']) . ' project(s)' : 'All') ?></td>
    <td><?= badge($u['status']) ?></td>
    <td class="small"><?= e(fmt_dt($u['last_login_at'])) ?></td>
  </tr><?php endforeach; ?></tbody>
</table></div></div>
