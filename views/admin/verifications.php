<h1 class="h4 mb-3">Verifications</h1>
<h2 class="h6">Waiting for review (<?= count($pending) ?>)</h2>
<?php if (!$pending): ?><p class="text-body-secondary small">Nothing waiting.</p><?php else: ?>
<div class="list-group shadow-sm mb-4"><?php foreach ($pending as $v): ?>
  <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('/admin/verifications/' . (int)$v['id'])) ?>"><span><strong><?= e($v['display_name'] ?? $v['name']) ?></strong> · <?= e(label('document_type', $v['document_type'])) ?><?= $v['college_name'] ? ' · ' . e($v['college_name']) : '' ?></span><small class="text-body-secondary"><?= e(ago($v['created_at'])) ?></small></a>
<?php endforeach; ?></div>
<?php endif; ?>
<h2 class="h6">Recent decisions</h2>
<div class="table-responsive"><table class="table table-sm small bg-body"><thead><tr><th>Student</th><th>Method</th><th>Status</th><th>Reviewer</th><th>When</th></tr></thead><tbody>
<?php foreach ($recent as $v): ?><tr><td><?= e($v['display_name'] ?? '—') ?></td><td><?= e($v['method'] === 'college_email' ? 'College email' : 'Document') ?></td><td><?= e($v['status']) ?></td><td><?= e($v['reviewer_name'] ?? ($v['method'] === 'college_email' ? 'automatic' : '—')) ?></td><td><?= e(ago($v['reviewed_at'] ?? $v['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
