<?php
use BharatSeo\Services\DashboardService;
use BharatSeo\Support\Labels;

$s = $stats;
$u = $currentUser;
$pq = $filters['project_id'] ? ['project_id' => $filters['project_id']] : [];
$kpis = [
    ['Projects', $s['projects'], 'kanban', 'primary', 'Active clients: ' . num($s['active_clients']), url('/projects')],
    ['Discovered opportunities', $s['discovered'], 'search', 'info', 'Prospects found (not backlinks)', url('/prospects', $pq)],
    ['Awaiting review', $s['needs_review'], 'hourglass-split', 'warning', num($s['on_hold']) . ' on manual hold', url('/prospects', $pq + ['status' => 'needs_review'])],
    ['Outreach approved / sent', num($s['messages_approved']) . ' / ' . num($s['messages_sent']), 'envelope-check', 'primary', num($s['messages_awaiting']) . ' awaiting approval · ' . num($s['replies']) . ' replies', url('/outreach', $pq)],
    ['Confirmed backlinks', $s['confirmed'], 'patch-check', 'success', 'Observed in fetched HTML', url('/backlinks', $pq + ['lifecycle' => 'confirmed'])],
    ['Active / lost / unverified', num($s['active']) . ' / ' . num($s['lost']) . ' / ' . num($s['unverified']), 'link-45deg', 'success', num($s['temporarily_unavailable']) . ' temporarily unavailable', url('/backlinks', $pq)],
    ['Avg. internal quality score', $s['avg_score'] ?: '—', 'speedometer', 'secondary', 'Heuristic 0–100, not DA/DR', url('/prospects', $pq + ['sort' => 'score'])],
];
$chart = [
    'labels' => array_map(static fn($p) => $p['name'], $s['per_project']),
    'series' => [
        ['name' => 'Prospects', 'values' => array_map('intval', array_column($s['per_project'], 'prospects'))],
        ['name' => 'Approved+', 'values' => array_map('intval', array_column($s['per_project'], 'approved'))],
        ['name' => 'Emails sent', 'values' => array_map('intval', array_column($s['per_project'], 'sent'))],
        ['name' => 'Active links', 'values' => array_map('intval', array_column($s['per_project'], 'active'))],
    ],
];
$donut = [];
foreach ($s['backlink_status'] as $st => $n) {
    $donut[] = ['label' => Labels::BACKLINK_STATUSES[$st] ?? $st, 'value' => (int)$n, 'key' => $st];
}
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
  <div>
    <h1 class="h3 mb-0">Welcome, <?= e(strtok($u->name, ' ')) ?></h1>
    <div class="text-body-secondary small">Data as of <?= e(local_now('d M Y, H:i')) ?> (<?= e(app()->timezone()->getName()) ?>)</div>
  </div>
  <form class="d-flex flex-wrap gap-2 align-items-end filter-bar" method="get" action="<?= e(url('/')) ?>">
    <div><label class="form-label small mb-0">Project</label><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $filters['project_id']]) ?></div>
    <div><label class="form-label small mb-0" for="from">From</label><input id="from" type="date" name="from" value="<?= e($filters['from']) ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0" for="to">To</label><input id="to" type="date" name="to" value="<?= e($filters['to']) ?>" class="form-control form-control-sm"></div>
    <button class="btn btn-sm btn-outline-primary">Apply</button>
  </form>
</div>

<?php foreach ($warnings as $w): ?>
  <div class="alert alert-<?= e($w['level']) ?> d-flex gap-2 align-items-start py-2"><i class="bi bi-exclamation-triangle mt-1"></i><div><?= e($w['text']) ?><?php if ($u->isSuperAdmin()): ?> <a href="<?= e(url('/system/queue')) ?>" class="alert-link">Open cron &amp; queue health</a><?php endif; ?></div></div>
<?php endforeach; ?>

<?php if ($u->isStaff()): ?>
<div class="quick-actions d-flex flex-wrap gap-2 mb-4">
  <a class="btn btn-primary btn-sm" href="<?= e(url('/projects/new')) ?>"><i class="bi bi-plus-lg"></i> Add project</a>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/discovery', $pq)) ?>"><i class="bi bi-search"></i> Discover opportunities</a>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/discovery', $pq)) ?>#import"><i class="bi bi-upload"></i> Import URLs</a>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/approvals', $pq)) ?>"><i class="bi bi-check2-square"></i> Review queue</a>
  <?php if ($filters['project_id']): ?>
    <form method="post" action="<?= e(url('/projects/' . $filters['project_id'] . '/check-backlinks')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Run backlink check</button></form>
  <?php else: ?>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/backlinks')) ?>" title="Choose a project to queue checks"><i class="bi bi-arrow-repeat"></i> Run backlink check</a>
  <?php endif; ?>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/reports', $pq)) ?>"><i class="bi bi-download"></i> Export report</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($kpis as [$label, $value, $icon, $tone, $sub, $href]): ?>
    <div class="col-12 col-sm-6 col-xl-3">
      <a class="card kpi-card h-100 text-decoration-none" href="<?= e($href) ?>">
        <div class="card-body d-flex gap-3">
          <div class="kpi-icon bg-<?= e($tone) ?>-subtle text-<?= e($tone) ?>-emphasis"><i class="bi bi-<?= e($icon) ?>"></i></div>
          <div class="min-w-0">
            <div class="kpi-label"><?= e($label) ?></div>
            <div class="kpi-value"><?= is_int($value) ? num($value) : e($value) ?></div>
            <div class="kpi-sub text-truncate"><?= e($sub) ?></div>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card card-soft h-100">
      <div class="card-header bg-transparent d-flex justify-content-between align-items-center"><span class="fw-semibold">Project-wise performance</span><span class="small text-body-secondary">Top 12 projects</span></div>
      <div class="card-body">
        <?php if ($s['per_project']): ?>
          <?= json_script('chart-projects', $chart) ?>
          <div class="chart-box" data-chart="bars" data-source="chart-projects"><div class="skeleton skeleton-chart"></div></div>
        <?php else: ?>
          <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'bar-chart', 'heading' => 'No project data yet', 'text' => 'Create a project and discover opportunities to see performance here.']) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-4">
    <div class="card card-soft h-100">
      <div class="card-header bg-transparent fw-semibold">Backlink status</div>
      <div class="card-body">
        <?php if ($donut): ?>
          <?= json_script('chart-links', $donut) ?>
          <div class="chart-box chart-box-sm" data-chart="donut" data-source="chart-links"><div class="skeleton skeleton-chart"></div></div>
        <?php else: ?>
          <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'link', 'heading' => 'No tracked backlinks', 'text' => 'Track links from approved prospects in the Backlink Monitor.']) ?>
        <?php endif; ?>
        <p class="small text-body-secondary mb-0 mt-2">“Confirmed” means our crawler observed the link in the page’s HTML. It is not a claim about any search engine’s index.</p>
      </div>
    </div>
  </div>
  <div class="col-12">
    <div class="card card-soft">
      <div class="card-header bg-transparent fw-semibold">Recent activity</div>
      <div class="card-body">
        <?php if (!$activity): ?>
          <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'activity', 'heading' => 'No activity yet']) ?>
        <?php else: ?>
          <ul class="timeline list-unstyled mb-0">
            <?php foreach ($activity as $a): ?>
              <li>
                <span class="timeline-dot"></span>
                <div class="d-flex flex-wrap justify-content-between gap-2">
                  <span><strong><?= e(DashboardService::actionLabel($a['action'])) ?></strong><?php if ($a['project_name']): ?> · <span class="text-body-secondary"><?= e($a['project_name']) ?></span><?php endif; ?></span>
                  <span class="small text-body-secondary"><?= e($a['user_name'] ?? 'System') ?> · <?= e(fmt_dt($a['created_at'])) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
