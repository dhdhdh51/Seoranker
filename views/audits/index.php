<div class="page-head">
  <div><h1 class="h3 mb-0">Site audit</h1><div class="small text-body-secondary">Crawls your own (ownership-verified) website and checks technical and on-page SEO: status codes, broken links, redirects, titles, meta descriptions, headings, content, images, canonicals, indexability, mobile viewport, structured data, HTTPS, robots.txt and sitemap.</div></div>
  <form method="get"><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $projectId]) ?></form>
</div>
<div class="row g-3">
  <?php if ($manageable): ?>
  <div class="col-xl-4">
    <div class="card card-soft"><div class="card-body">
      <h2 class="h5"><i class="bi bi-clipboard2-pulse text-primary"></i> New audit</h2>
      <form method="post" action="<?= e(url('/audits')) ?>" class="row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label small">Project</label><select name="project_id" class="form-select" required><option value="">Choose…</option><?php foreach ($manageable as $id => $l): ?><option value="<?= (int)$id ?>"<?= selected($projectId ?? '', $id) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small">Start URL (optional — defaults to the project website)</label><input name="start_url" class="form-control" placeholder="https://…"></div>
        <div class="col-6"><label class="form-label small">Max pages</label><input type="number" name="max_pages" class="form-control" value="100" min="1" max="500"></div>
        <div class="col-6"><label class="form-label small">Max click depth</label><input type="number" name="max_depth" class="form-control" value="4" min="0" max="10"></div>
        <div class="col-12"><button class="btn btn-primary w-100">Start audit</button></div>
        <div class="col-12 small text-body-secondary">The site must be verified on the project page. robots.txt is respected and requests are spaced out so your site is not overloaded.</div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="<?= $manageable ? 'col-xl-8' : 'col-12' ?>">
    <div class="card card-soft">
      <?php if (!$audits): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'clipboard2-pulse', 'heading' => 'No audits yet', 'text' => 'Run the first audit to get a full SEO health report.']) ?><?php else: ?>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Project</th><th>Start URL</th><th class="text-center">Score</th><th class="text-end">Pages</th><th class="text-end">Errors / Warnings</th><th>Status</th><th>When</th></tr></thead>
        <tbody><?php foreach ($audits as $a): ?><tr>
          <td><a class="fw-semibold" href="<?= e(url('/audits/' . $a['id'])) ?>"><?= e($a['project_name']) ?></a></td>
          <td class="small text-truncate" style="max-width:220px"><?= e($a['start_url']) ?></td>
          <td class="text-center"><?php if ($a['score'] !== null): ?><span class="score-pill score-<?= $a['score'] >= 80 ? 'high' : ($a['score'] >= 60 ? 'review' : 'low') ?>"><?= (int)$a['score'] ?></span><?php else: ?>—<?php endif; ?></td>
          <td class="text-end"><?= num((int)$a['pages_crawled']) ?></td>
          <td class="text-end"><?= num((int)$a['error_count']) ?> / <?= num((int)$a['warning_count']) ?></td>
          <td><?= badge($a['status']) ?></td>
          <td class="small text-nowrap"><?= e(fmt_dt($a['created_at'])) ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
</div>
