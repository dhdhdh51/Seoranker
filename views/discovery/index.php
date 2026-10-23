<?php
use BharatSeo\Support\Labels;
/** @var \BharatSeo\Services\SearchProviderService $search */
$catOptions = static function (string $selected = 'other'): string {
    $h = '';
    foreach (Labels::CATEGORIES as $k => $l) {
        $h .= '<option value="' . e($k) . '"' . selected($selected, $k) . '>' . e($l) . '</option>';
    }
    return $h;
};
$projectField = static function () use ($projects, $projectId): string {
    $h = '<select name="project_id" class="form-select" required><option value="">Choose project…</option>';
    foreach ($projects as $id => $l) {
        $h .= '<option value="' . (int)$id . '"' . selected($projectId ?? '', $id) . '>' . e($l) . '</option>';
    }
    return $h . '</select>';
};
?>
<div class="page-head">
  <div><h1 class="h3 mb-0">Opportunity discovery</h1><div class="small text-body-secondary">Legitimate sources only: manual research, CSV exports you are licensed to use, public datasets, authorized search APIs and public pages (robots.txt respected). Google result pages are never scraped.</div></div>
  <form method="get" class="d-flex gap-2"><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $projectId, 'allLabel' => 'All my projects']) ?></form>
</div>

<?php if (!$projects): ?>
  <div class="card card-soft"><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'kanban', 'heading' => 'No projects you can manage', 'actionUrl' => url('/projects/new'), 'actionLabel' => 'Add project']) ?></div>
<?php else: ?>
<div class="row g-3 mb-4">
  <div class="col-xl-6">
    <div class="card card-soft h-100"><div class="card-body">
      <h2 class="h5"><i class="bi bi-pencil-square text-primary"></i> Manual URL entry</h2>
      <form method="post" action="<?= e(url('/discovery/manual')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small">Project</label><?= $projectField() ?></div>
        <div class="col-md-6"><label class="form-label small">Category</label><select name="category" class="form-select"><?= $catOptions('resource_page') ?></select></div>
        <div class="col-12"><label class="form-label small" for="urls">URLs (one per line, max 500)</label><textarea id="urls" name="urls" class="form-control font-monospace small" rows="5" required placeholder="https://example.org/useful-resources/"><?= e(old('urls')) ?></textarea></div>
        <div class="col-md-6"><label class="form-label small" for="ce">Public contact email (single URL only)</label><input id="ce" name="contact_email" type="email" class="form-control"></div>
        <div class="col-md-6"><label class="form-label small" for="tp">Proposed target page</label><select id="tp" name="target_page_id" class="form-select"><option value="">Auto</option><?php foreach ($targets as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e(str_limit($t['url'], 60)) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small" for="ev">Evidence (why is this an opportunity?)</label><textarea id="ev" name="evidence" class="form-control" rows="2" maxlength="5000"></textarea></div>
        <div class="col-12"><button class="btn btn-primary">Add &amp; evaluate</button></div>
      </form>
    </div></div>
  </div>
  <div class="col-xl-6" id="import">
    <div class="card card-soft h-100"><div class="card-body">
      <h2 class="h5"><i class="bi bi-filetype-csv text-primary"></i> CSV import</h2>
      <p class="small text-body-secondary mb-2">Accepted: prospect lists, public datasets, research-tool prospect lists and <strong>competitor backlink exports</strong> (Ahrefs, Semrush, Moz, Majestic, Google Search Console — UTF-8/UTF-16, comma/semicolon/tab). Required column: a URL column such as <code>url</code>, <code>Referring page URL</code> or <code>Source URL</code>. Optional: <code>title, category, contact_email, contact_name, evidence, notes, target_page</code>. Max <?= (int)$maxUploadMb ?> MB. Duplicates are skipped automatically.</p>
      <form method="post" action="<?= e(url('/discovery/import')) ?>" enctype="multipart/form-data" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small">Project</label><?= $projectField() ?></div>
        <div class="col-md-6"><label class="form-label small" for="type">File type</label><select id="type" name="type" class="form-select"><option value="prospects">Prospect CSV</option><option value="competitor_backlinks">Competitor backlink export</option><option value="dataset">Public dataset</option><option value="prospect_list">Research-tool prospect list</option></select></div>
        <div class="col-md-6"><label class="form-label small" for="cat2">Default category</label><select id="cat2" name="category" class="form-select"><option value="">Auto</option><?= $catOptions('') ?></select></div>
        <div class="col-md-6"><label class="form-label small" for="file">CSV file</label><input id="file" type="file" name="file" accept=".csv,.tsv,.txt,text/csv" class="form-control" required></div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-upload"></i> Upload &amp; queue</button> <a class="small ms-2" href="<?= e(asset('samples/prospects-sample.csv')) ?>">Download sample CSV</a></div>
      </form>
    </div></div>
  </div>
  <div class="col-xl-6">
    <div class="card card-soft h-100"><div class="card-body">
      <h2 class="h5"><i class="bi bi-globe2 text-primary"></i> Search-provider API</h2>
      <?php if (!$search->isAvailable()): ?>
        <div class="alert alert-secondary small mb-0">Not configured. A Super Admin can enable the <em>Search-provider API</em> module and add a Google Programmable Search, Brave Search or authorized SearXNG endpoint in Settings. Free quotas are protected by a daily limit. Until then, use manual entry, CSV or public pages.</div>
      <?php else: ?>
        <p class="small text-body-secondary">Provider: <strong><?= e($search->providerName()) ?></strong>. Leave queries blank to build footprints from project keywords.</p>
        <form method="post" action="<?= e(url('/discovery/search')) ?>" class="row g-2">
          <?= csrf_field() ?>
          <div class="col-md-6"><label class="form-label small">Project</label><?= $projectField() ?></div>
          <div class="col-md-6"><label class="form-label small">Opportunity type</label><select name="category" class="form-select"><?= $catOptions('resource_page') ?></select></div>
          <div class="col-12"><label class="form-label small" for="queries">Custom queries (optional, one per line, max 10)</label><textarea id="queries" name="queries" class="form-control small" rows="3"></textarea></div>
          <div class="col-12"><button class="btn btn-primary">Queue search</button></div>
        </form>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-xl-6">
    <div class="card card-soft h-100"><div class="card-body">
      <h2 class="h5"><i class="bi bi-file-earmark-richtext text-primary"></i> Public page link extraction</h2>
      <p class="small text-body-secondary">Point to a public resource list, association member directory or supplier page. In-content outbound links become candidates. Social networks, hidden and sponsored links are skipped.</p>
      <form method="post" action="<?= e(url('/discovery/public-page')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small">Project</label><?= $projectField() ?></div>
        <div class="col-md-6"><label class="form-label small">Category for found links</label><select name="category" class="form-select"><?= $catOptions('resource_page') ?></select></div>
        <div class="col-12"><label class="form-label small" for="pu">Public page URL</label><input id="pu" name="page_url" class="form-control" required placeholder="https://association.example.org/members"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="same_topic_only" value="1" id="sto"><label class="form-check-label small" for="sto">Only keep links whose anchor/URL mentions a project keyword</label></div></div>
        <div class="col-12"><button class="btn btn-primary">Queue extraction</button></div>
      </form>
    </div></div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-7">
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Discovery runs</div>
      <?php if (!$runs): ?><div class="card-body small text-body-secondary">No runs yet.</div><?php else: ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>#</th><th>Project</th><th>Source</th><th>Status</th><th class="text-end">Input</th><th class="text-end">New</th><th class="text-end">Dup.</th><th class="text-end">Rejected</th><th>When</th></tr></thead>
        <tbody><?php foreach ($runs as $r): ?>
          <tr><td><?= (int)$r['id'] ?></td><td class="small"><?= e($r['project_name']) ?></td><td class="small" title="<?= e($r['source_detail']) ?>"><?= e(Labels::SOURCES[$r['source_type']] ?? $r['source_type']) ?><div class="text-body-secondary text-truncate" style="max-width:220px"><?= e($r['source_detail']) ?></div></td>
          <td><?= badge($r['status']) ?><?php if ($r['error_message']): ?><div class="small text-danger"><?= e(str_limit($r['error_message'], 80)) ?></div><?php endif; ?></td>
          <td class="text-end"><?= num((int)$r['total_input']) ?></td><td class="text-end"><?= num((int)$r['created_count']) ?></td><td class="text-end"><?= num((int)$r['duplicate_count']) ?></td><td class="text-end"><?= num((int)$r['rejected_count']) ?></td><td class="small text-nowrap"><?= e(fmt_dt($r['created_at'])) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">CSV imports</div>
      <?php if (!$imports): ?><div class="card-body small text-body-secondary">No imports yet.</div><?php else: ?>
      <ul class="list-group list-group-flush small">
        <?php foreach ($imports as $i): ?>
          <li class="list-group-item">
            <div class="d-flex justify-content-between"><span class="fw-semibold text-truncate"><?= e($i['original_filename']) ?></span><?= badge($i['status']) ?></div>
            <div class="text-body-secondary"><?= e($i['project_name']) ?> · <?= num((int)$i['total_rows']) ?> rows · <?= num((int)$i['created_rows']) ?> new · <?= num((int)$i['duplicate_rows']) ?> duplicate · <?= num((int)$i['error_rows']) ?> rejected</div>
            <?php $errs = json_decode((string)$i['errors'], true) ?: []; if ($errs): ?><details><summary class="small">Errors (<?= count($errs) ?>)</summary><ul class="small mb-0"><?php foreach (array_slice($errs, 0, 10) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul><?php endif; ?>
    </div>
  </div>
</div>
