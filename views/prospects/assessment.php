<?php use BharatSeo\Services\QualityScorer; ?>
<div class="page-head">
  <div><div class="small"><a href="<?= e(url('/prospects/' . $p['id'])) ?>">← Back to prospect</a></div><h1 class="h4 mb-0">Quality assessment · <?= e($p['domain']) ?></h1></div>
</div>
<div class="alert alert-info small">
  <strong>How the score works.</strong> A transparent internal heuristic from 0–100: topical relevance (30), editorial quality (25), trust indicators (20), technical health (15), link placement &amp; context (10).
  Bands: <strong>80–100</strong> high-priority manual review · <strong>60–79</strong> review recommended · <strong>&lt;60</strong> low priority or reject.
  Missing evidence is shown as <em>Unknown</em> and earns no points. Critical risk flags force a manual hold regardless of score.
  This is <strong>not</strong> Google PageRank, Domain Authority or Domain Rating, and it does not predict rankings.
</div>
<?php if (!$assessments): ?>
  <div class="card card-soft"><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'hourglass', 'heading' => 'Not assessed yet', 'text' => 'The evaluation job has not run yet.']) ?></div>
<?php endif; ?>
<?php foreach ($assessments as $i => $a): $components = json_decode((string)$a['components'], true) ?: []; $flags = json_decode((string)$a['risk_flags'], true) ?: []; ?>
  <div class="card card-soft mb-3<?= $a['is_current'] ? ' border-primary' : '' ?>">
    <div class="card-header bg-transparent d-flex flex-wrap justify-content-between gap-2">
      <span><strong><?= (int)$a['total_score'] ?>/100</strong> · <?= e(QualityScorer::bandLabel($a['band'])) ?> <?= $a['is_current'] ? badge('active', 'Current') : '' ?> <?= $a['has_critical'] ? badge('critical', 'Critical risk') : '' ?></span>
      <span class="small text-body-secondary"><?= e(fmt_dt($a['created_at'])) ?> · <?= e($a['assessed_by_name'] ?? 'automatic') ?> · scorer v<?= e($a['scorer_version']) ?></span>
    </div>
    <?php if ($i === 0 || $a['is_current']): ?>
    <div class="card-body">
      <div class="row g-3">
        <?php foreach ($components as $c): ?>
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <div class="d-flex justify-content-between"><span class="fw-semibold"><?= e($c['label']) ?></span><span><?= (int)$c['score'] ?>/<?= (int)$c['max'] ?></span></div>
              <div class="small mb-2"><?= $c['status'] === 'unknown' ? badge('unverified', 'Unknown') : ($c['status'] === 'partial' ? badge('none', 'Partly unknown') : badge('ok', 'Scored')) ?></div>
              <ul class="small mb-0 ps-3"><?php foreach ($c['notes'] as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($flags): ?><h3 class="h6 mt-3">Flags</h3><ul class="small mb-0"><?php foreach ($flags as $f): ?><li><strong class="text-<?= $f['severity'] === 'critical' ? 'danger' : ($f['severity'] === 'warning' ? 'warning-emphasis' : 'body-secondary') ?>"><?= e($f['severity']) ?></strong> · <?= e($f['code']) ?> — <?= e($f['message']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
