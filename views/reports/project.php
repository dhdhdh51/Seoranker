<?php
use BharatSeo\Support\Labels;
$p = $r['project'];
$bar = static function (array $pairs): string {
    $max = max(1, ...array_values($pairs ?: [0]));
    $h = '';
    foreach ($pairs as $label => $n) {
        $h .= '<div class="report-bar"><span class="report-bar-label">' . e($label) . '</span><span class="report-bar-track"><span class="report-bar-fill" style="width:' . (int)round($n / $max * 100) . '%"></span></span><span class="report-bar-n">' . num((int)$n) . '</span></div>';
    }
    return $h;
};
$catPairs = [];
foreach ($r['by_category'] as $c) {
    $catPairs[Labels::category($c['category'])] = (int)$c['n'];
}
$statusPairs = [];
foreach ($r['by_status'] as $c) {
    $statusPairs[Labels::PROSPECT_STATUSES[$c['status']] ?? $c['status']] = (int)$c['n'];
}
$replyPairs = [];
foreach ($r['reply_status'] as $c) {
    $replyPairs[ucfirst($c['reply_status'])] = (int)$c['n'];
}
$linkPairs = [];
foreach ($r['backlink_status'] as $c) {
    $linkPairs[Labels::BACKLINK_STATUSES[$c['status']] ?? $c['status']] = (int)$c['n'];
}
$q = array_filter(['project_id' => $p['id'], 'from' => $r['from'], 'to' => $r['to']]);
?>
<div class="d-flex justify-content-between align-items-start mb-4 no-print">
  <a href="<?= e(url('/projects/' . $p['id'])) ?>">← Back to project</a>
  <div class="d-flex gap-2">
    <?php if ($canExport): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/exports/report', $q)) ?>"><i class="bi bi-download"></i> CSV</a><?php endif; ?>
    <button class="btn btn-primary btn-sm" type="button" data-print><i class="bi bi-printer"></i> Print / PDF</button>
  </div>
</div>
<header class="report-head mb-4">
  <div class="d-flex align-items-center gap-2"><span class="brand-mark">B</span><span class="fw-bold">Bharat SEO</span></div>
  <h1 class="h3 mt-3 mb-1">Backlink report · <?= e($p['name']) ?></h1>
  <div class="text-body-secondary small"><?= e($p['client_name']) ?> · <?= e($p['domain']) ?> · Period: <?= e($r['from'] ?: 'all time') ?> – <?= e($r['to'] ?: 'today') ?></div>
  <div class="text-body-secondary small">Generated <?= e(fmt_dt($r['generated_at'], 'd M Y, H:i T')) ?> from live records.</div>
</header>
<?php if (!$r['cron_active']): ?><div class="alert alert-warning small">Automation (cron) was not running when this report was generated; monitoring data may be stale.</div><?php endif; ?>
<section class="row g-3 mb-4">
  <?php foreach ([['Awaiting review', $r['awaiting_review']], ['Approved', $r['approved']], ['Outreach sent', $r['outreach_sent']], ['Active backlinks', $r['active_backlinks']], ['Confirmed', $r['confirmed_backlinks']], ['Lost', $r['lost_backlinks']], ['Unverified', $r['unverified_backlinks']], ['Newly detected', $r['new_backlinks']]] as [$l, $n]): ?>
    <div class="col-6 col-md-3"><div class="report-kpi"><div class="kpi-label"><?= e($l) ?></div><div class="kpi-value"><?= num((int)$n) ?></div></div></div>
  <?php endforeach; ?>
</section>
<section class="row g-4 mb-4">
  <div class="col-md-6"><h2 class="h6 text-uppercase">Prospects by category</h2><?= $catPairs ? $bar($catPairs) : '<p class="small text-body-secondary">No prospects.</p>' ?></div>
  <div class="col-md-6"><h2 class="h6 text-uppercase">Prospects by status</h2><?= $statusPairs ? $bar($statusPairs) : '<p class="small text-body-secondary">No prospects.</p>' ?></div>
  <div class="col-md-6"><h2 class="h6 text-uppercase">Internal quality score distribution</h2><?= $bar($r['score_distribution']) ?></div>
  <div class="col-md-6"><h2 class="h6 text-uppercase">Link attributes (present links)</h2><?= $bar($r['link_attributes']) ?></div>
  <div class="col-md-6"><h2 class="h6 text-uppercase">Outreach reply status</h2><?= $replyPairs ? $bar($replyPairs) : '<p class="small text-body-secondary">No outreach sent in this period. Reply status is recorded manually by the team.</p>' ?></div>
  <div class="col-md-6"><h2 class="h6 text-uppercase">Backlink status</h2><?= $linkPairs ? $bar($linkPairs) : '<p class="small text-body-secondary">No tracked backlinks.</p>' ?></div>
</section>
<section class="mb-4">
  <h2 class="h6 text-uppercase">Monitoring &amp; health</h2>
  <ul class="small">
    <li>Last monitoring check: <?= e($r['last_monitoring'] ? fmt_dt($r['last_monitoring']) : 'never') ?></li>
    <li>Opportunities on manual hold (critical risk): <?= num((int)$r['on_hold']) ?></li>
    <li>Links temporarily unavailable (awaiting confirmation): <?= num((int)$r['temporarily_unavailable']) ?></li>
    <li>Unresolved failed jobs: <?= num((int)$r['failed_jobs']) ?> · Failed outreach messages: <?= num((int)$r['failed_messages']) ?></li>
  </ul>
</section>
<section class="mb-4">
  <h2 class="h6 text-uppercase">Status changes (latest 100)</h2>
  <?php if (!$r['changes']): ?><p class="small text-body-secondary">No status changes in this period.</p><?php else: ?>
  <table class="table table-sm small"><thead><tr><th>When</th><th>Referring page</th><th>Change</th><th>Observation</th></tr></thead><tbody>
  <?php foreach ($r['changes'] as $c): ?><tr><td class="text-nowrap"><?= e(fmt_dt($c['checked_at'])) ?></td><td class="text-break"><?= e($c['referring_page_url']) ?></td><td class="text-nowrap"><?= e(Labels::BACKLINK_STATUSES[$c['status_before']] ?? $c['status_before']) ?> → <?= e(Labels::BACKLINK_STATUSES[$c['status_after']] ?? $c['status_after']) ?></td><td><?= e($c['observation']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<footer class="small text-body-secondary border-top pt-3">
  Quality scores are Bharat SEO’s internal heuristic, not Google PageRank, Domain Authority or Domain Rating, and are not a ranking guarantee.
  Backlink statuses are observations of fetched HTML by our crawler and are not search-engine index data. Discovered opportunities are not acquired backlinks.
</footer>
