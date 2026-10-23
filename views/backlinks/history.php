<?php use BharatSeo\Support\Labels; ?>
<div class="page-head">
  <h1 class="h3 mb-0">Backlink history</h1>
  <div class="d-flex flex-wrap gap-2">
    <form method="get" class="d-flex gap-2 align-items-center">
      <?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $projectId]) ?>
      <div class="form-check text-nowrap"><input class="form-check-input" type="checkbox" name="changes" value="1" id="co" data-autosubmit<?= checked($changesOnly) ?>><label class="form-check-label small" for="co">Status changes only</label></div>
    </form>
    <a class="btn btn-outline-secondary" href="<?= e(url('/exports/history', ['project_id' => $projectId])) ?>"><i class="bi bi-download"></i> Export CSV</a>
  </div>
</div>
<div class="card card-soft">
  <?php if (!$rows): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'clock-history', 'heading' => 'No checks recorded yet']) ?><?php else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead><tr><th>Checked</th><th>Project</th><th>Referring page</th><th>HTTP</th><th>Observation</th><th>Status change</th><th>Note</th></tr></thead>
    <tbody><?php foreach ($rows as $h): ?><tr class="<?= $h['status_changed'] ? 'table-warning' : '' ?>">
      <td class="small text-nowrap"><?= e(fmt_dt($h['checked_at'])) ?></td><td class="small"><?= e($h['project_name']) ?></td>
      <td class="small mw-0"><a class="d-block text-truncate" href="<?= e(url('/backlinks/' . $h['backlink_id'])) ?>"><?= e($h['referring_page_url']) ?></a></td>
      <td class="small"><?= $h['http_status'] ? (int)$h['http_status'] : '—' ?></td><td class="small"><?= e($h['observation']) ?></td>
      <td class="small text-nowrap"><?= e(Labels::BACKLINK_STATUSES[$h['status_before']] ?? $h['status_before']) ?><?= $h['status_changed'] ? ' → <strong>' . e(Labels::BACKLINK_STATUSES[$h['status_after']] ?? $h['status_after']) . '</strong>' : '' ?></td>
      <td class="small text-break"><?= e(str_limit($h['error'] ?? $h['observed_anchor'] ?? '', 70)) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div>
