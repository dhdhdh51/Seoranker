<h1 class="h4 mb-3">Audit log</h1>
<form class="row g-2 mb-3" method="get" action="<?= e(url('/admin/audit')) ?>">
  <div class="col-md-4"><input class="form-control form-control-sm" name="action" value="<?= e($f['action']) ?>" placeholder="Action prefix, e.g. verification." aria-label="Action"></div>
  <div class="col-md-2"><input class="form-control form-control-sm" name="actor" value="<?= e($f['actor']) ?>" placeholder="Actor id" aria-label="Actor id"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Filter</button></div>
</form>
<div class="table-responsive"><table class="table table-sm small bg-body"><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="text-nowrap"><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['actor_name'] ?? ($r['actor_id'] ? '#' . $r['actor_id'] : 'system')) ?></td><td><code><?= e($r['action']) ?></code></td><td><?= e($r['target_type'] ? $r['target_type'] . ' #' . $r['target_id'] : '') ?></td><td class="text-break"><?= e(str_limit($r['details'] ?? '', 120)) ?></td><td><?= e($r['ip_address']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?= \Flatmates\Http\View::partial('partials/pager', ['pager' => $pager]) ?>
