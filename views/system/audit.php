<div class="page-head">
  <div><h1 class="h3 mb-0">Audit log</h1><div class="small text-body-secondary">Append-only and hash-chained. The application never edits or deletes entries; verification detects tampering.</div></div>
  <form method="post" action="<?= e(url('/system/audit/verify')) ?>"><?= csrf_field() ?><button class="btn btn-outline-primary"><i class="bi bi-shield-check"></i> Verify integrity</button></form>
</div>
<form method="get" class="card card-soft mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label small mb-0">Action starts with</label><input name="action" value="<?= e($action) ?>" class="form-control form-control-sm" placeholder="approval. / outreach. / auth."></div>
  <div><label class="form-label small mb-0">Project</label><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $projectId]) ?></div>
  <button class="btn btn-sm btn-primary">Filter</button>
</div></form>
<div class="card card-soft">
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>#</th><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Project</th><th>Details</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($rows as $a): ?><tr>
      <td class="small"><?= (int)$a['id'] ?></td><td class="small text-nowrap"><?= e(fmt_dt($a['created_at'], 'd M Y H:i:s')) ?></td><td class="small"><?= e($a['user_name'] ?? 'System') ?></td>
      <td class="small"><code><?= e($a['action']) ?></code></td><td class="small"><?= e($a['entity_type']) ?><?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?></td><td class="small"><?= e($a['project_name'] ?? '') ?></td>
      <td class="small text-break"><code class="small"><?= e(str_limit((string)$a['details'], 160)) ?></code></td><td class="small"><?= e($a['ip_address']) ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="small text-body-secondary">No entries.</td></tr><?php endif; ?></tbody>
  </table></div>
  <div class="card-footer bg-transparent"><?= \BharatSeo\Http\View::partial('partials/pagination', ['pager' => $pager]) ?></div>
</div>
