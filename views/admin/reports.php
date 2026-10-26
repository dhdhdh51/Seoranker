<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2"><h1 class="h4 mb-0">Reports</h1>
<div class="btn-group btn-group-sm"><?php foreach (['open' => 'Open', 'escalated' => 'Escalated', 'actioned' => 'Actioned', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $v): ?><a class="btn btn-outline-secondary<?= $status === $k ? ' active' : '' ?>" href="<?= e(url('/admin/reports', ['status' => $k])) ?>"><?= e($v) ?></a><?php endforeach; ?></div></div>
<?php if (!$rows): ?><p class="text-body-secondary">No reports here. 🎉</p><?php else: ?>
<div class="table-responsive"><table class="table table-hover align-middle small bg-body">
<thead><tr><th>#</th><th>Reason</th><th>Target</th><th>Reported user</th><th>Status</th><th>When</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><a href="<?= e(url('/admin/reports/' . (int)$r['id'])) ?>"><?= (int)$r['id'] ?></a></td>
  <td><?= e(label('report_reason', $r['reason'])) ?> <?= $r['severity'] === 'serious' ? '<span class="badge text-bg-danger">serious</span>' : '' ?></td>
  <td><?= e($r['target_type']) ?></td>
  <td><?= e($r['target_name'] ?? '—') ?> <span class="text-body-secondary">(<?= e($r['target_status'] ?? '') ?>)</span></td>
  <td><span class="badge text-bg-<?= $r['status'] === 'escalated' ? 'danger' : ($r['status'] === 'open' ? 'warning' : 'secondary') ?>"><?= e($r['status']) ?></span></td>
  <td><?= e(ago($r['created_at'])) ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
