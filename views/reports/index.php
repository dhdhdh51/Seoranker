<div class="page-head"><h1 class="h3 mb-0">Reports &amp; exports</h1></div>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card card-soft h-100"><div class="card-body">
      <h2 class="h5"><i class="bi bi-file-earmark-bar-graph text-primary"></i> Printable project report</h2>
      <p class="small text-body-secondary">Built from live database records with the generation date. Use your browser’s Print → Save as PDF.</p>
      <form method="get" class="row g-2" action="<?= e(url('/reports')) ?>">
        <div class="col-12"><select name="project" class="form-select" required><option value="">Choose project…</option><?php foreach ($projects as $id => $l): ?><option value="<?= (int)$id ?>"<?= selected($projectId ?? '', $id) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">From</label><input type="date" name="from" class="form-control"></div>
        <div class="col-6"><label class="form-label small">To</label><input type="date" name="to" class="form-control"></div>
        <div class="col-12"><button class="btn btn-primary">Open report</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card card-soft h-100"><div class="card-body">
      <h2 class="h5"><i class="bi bi-filetype-csv text-primary"></i> CSV exports</h2>
      <p class="small text-body-secondary">Spreadsheet formula injection is neutralised in every cell.</p>
      <form method="get" class="row g-2" action="<?= e(url('/reports')) ?>">
        <div class="col-12"><select name="project_id" class="form-select"><option value="">All accessible projects</option><?php foreach ($projects as $id => $l): ?><option value="<?= (int)$id ?>"<?= selected($projectId ?? '', $id) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><select name="type" class="form-select"><option value="report">Project report summary (select a project)</option><option value="prospects">Prospects</option><option value="backlinks">Backlinks</option><option value="history">Backlink check history</option><option value="drafts">Outreach drafts &amp; messages</option></select></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-download"></i> Download CSV</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-12">
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Recent exports</div>
      <?php if (!$exports): ?><div class="card-body small text-body-secondary">No exports yet.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>When</th><th>Type</th><th>Project</th><th>User</th><th class="text-end">Rows</th></tr></thead>
      <tbody><?php foreach ($exports as $x): ?><tr><td class="small"><?= e(fmt_dt($x['created_at'])) ?></td><td class="small"><?= e($x['type']) ?></td><td class="small"><?= e($x['project_name'] ?? 'All') ?></td><td class="small"><?= e($x['user_name']) ?></td><td class="small text-end"><?= num((int)$x['row_count']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
