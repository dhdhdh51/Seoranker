<?php use Flatmates\Security\User; ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">Users</h1>
<?php if ($currentUser->isAdmin()): ?><button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#newStaff" aria-expanded="false" aria-controls="newStaff"><i class="bi bi-person-plus"></i> Add staff</button><?php endif; ?></div>
<?php if ($currentUser->isAdmin()): ?>
<form class="collapse card card-body border-0 shadow-sm mb-3" id="newStaff" method="post" action="<?= e(url('/admin/staff')) ?>"><?= csrf_field() ?>
  <div class="row g-2">
    <div class="col-md-3"><input class="form-control form-control-sm" name="name" placeholder="Name" required aria-label="Name"></div>
    <div class="col-md-3"><input class="form-control form-control-sm" type="email" name="email" placeholder="Email" required aria-label="Email"></div>
    <div class="col-md-3"><input class="form-control form-control-sm" type="password" name="password" placeholder="Temporary password" required minlength="8" autocomplete="new-password" aria-label="Password"></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="role" aria-label="Role"><option value="moderator">Moderator</option><option value="super_admin">Super Admin</option></select></div>
    <div class="col-12 small"><?php foreach (User::PERMISSIONS as $k => $v): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?= e($k) ?>" id="np_<?= e($k) ?>" checked><label class="form-check-label" for="np_<?= e($k) ?>"><?= e($v) ?></label></div><?php endforeach; ?></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Create</button></div>
  </div>
</form>
<?php endif; ?>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/admin/users')) ?>">
  <div class="col-md-5"><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="Search name or email" aria-label="Search"></div>
  <div class="col-6 col-md-3"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (\Flatmates\Support\Options::GROUPS['status'] as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($f['status'], $k) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="role" aria-label="Role"><option value="">Any role</option><?php foreach (\Flatmates\Support\Options::GROUPS['role'] as $k => $v): ?><option value="<?= e($k) ?>"<?= selected($f['role'], $k) ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Filter</button></div>
</form>
<div class="table-responsive"><table class="table table-hover align-middle small bg-body"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Profile</th><th>Reports</th><th>Joined</th><th>Last seen</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><a href="<?= e(url('/admin/users/' . (int)$r['id'])) ?>"><?= e($r['name']) ?></a><?= $r['verified'] ? ' <i class="bi bi-patch-check-fill text-primary"></i>' : '' ?><div class="text-body-secondary"><?= e($r['email']) ?></div></td>
  <td><?= e(label('role', $r['role'])) ?></td>
  <td><span class="badge text-bg-<?= $r['status'] === 'active' ? 'success' : ($r['status'] === 'suspended' ? 'danger' : 'secondary') ?>"><?= e($r['status']) ?></span><?= $r['deletion_requested_at'] ? ' <span class="badge text-bg-warning">deleting</span>' : '' ?></td>
  <td><?= $r['completeness'] !== null ? (int)$r['completeness'] . '%' : '—' ?></td>
  <td><?= (int)$r['reports_against'] ?: '' ?></td>
  <td><?= e(fmt_dt($r['created_at'], 'd M Y')) ?></td><td><?= e(ago($r['last_seen_at'])) ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<?= \Flatmates\Http\View::partial('partials/pager', ['pager' => $pager]) ?>
