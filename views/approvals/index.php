<?php use BharatSeo\Support\Labels; ?>
<div class="page-head">
  <div><h1 class="h3 mb-0">Approval queue</h1><div class="small text-body-secondary">External outreach is never sent without the approvals required by each project’s policy. Every decision is recorded in the audit log.</div></div>
  <form method="get"><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $projectId]) ?></form>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-msg" type="button">Outreach messages <span class="badge text-bg-warning"><?= count($messages) ?></span></button></li>
  <?php if ($currentUser->isStaff()): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-pros" type="button">Prospects needing review <span class="badge text-bg-warning"><?= count($prospects) ?></span></button></li><?php endif; ?>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-hist" type="button">Recent decisions</button></li>
</ul>
<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-msg">
    <?php if (!$messages): ?><div class="card card-soft"><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'check2-all', 'heading' => 'Nothing awaiting your approval']) ?></div><?php endif; ?>
    <?php foreach ($messages as $a): ?>
      <div class="card card-soft mb-3"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
          <div><span class="fw-semibold"><?= e($a['subject']) ?></span><div class="small text-body-secondary"><?= e($a['project_name']) ?> · <?= e($a['prospect_domain']) ?> · score <?= $a['quality_score'] !== null ? (int)$a['quality_score'] : '—' ?> · rev <?= (int)$a['revision'] ?> · approving as <strong><?= e($a['required_party']) ?></strong></div></div>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/outreach/messages/' . $a['entity_id'])) ?>">Open editor</a>
        </div>
        <?php if ($a['generator_note']): ?><div class="alert alert-warning small py-1"><?= e($a['generator_note']) ?></div><?php endif; ?>
        <div class="small text-body-secondary">To: <?= $currentUser->isStaff() ? e($a['recipient_email']) : 'the publicly listed contact of ' . e($a['prospect_domain']) ?></div>
        <pre class="email-pre email-pre-short"><?= e($a['body']) ?></pre>
        <form method="post" action="<?= e(url('/approvals/' . $a['id'] . '/decide')) ?>" class="d-flex flex-wrap gap-2 mt-2">
          <?= csrf_field() ?>
          <input name="note" class="form-control form-control-sm flex-grow-1 w-auto" placeholder="Note (required to reject)" maxlength="1000">
          <button class="btn btn-sm btn-success" name="decision" value="approve"><i class="bi bi-check-lg"></i> Approve</button>
          <button class="btn btn-sm btn-outline-danger" name="decision" value="reject"><i class="bi bi-x-lg"></i> Reject</button>
        </form>
      </div></div>
    <?php endforeach; ?>
  </div>
  <?php if ($currentUser->isStaff()): ?>
  <div class="tab-pane fade" id="tab-pros">
    <div class="card card-soft">
      <?php if (!$prospects): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'check2-all', 'heading' => 'No prospects waiting for review']) ?><?php else: ?>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Prospect</th><th>Category</th><th class="text-center">Score</th><th>Risk</th><th class="text-end">Decision</th></tr></thead>
        <tbody><?php foreach ($prospects as $p): ?><tr>
          <td class="mw-0"><a class="fw-semibold d-block text-truncate" href="<?= e(url('/prospects/' . $p['id'])) ?>"><?= e($p['title'] ?: $p['domain']) ?></a><span class="small text-body-secondary"><?= e($p['project_name']) ?> · <?= e($p['domain']) ?></span></td>
          <td class="small"><?= e(Labels::category($p['category'])) ?></td>
          <td class="text-center"><?php if ($p['quality_score'] !== null): ?><span class="score-pill score-<?= e($p['score_band']) ?>"><?= (int)$p['quality_score'] ?></span><?php endif; ?></td>
          <td><?= $p['manual_hold'] ? badge('critical', 'Manual hold') : '<span class="small text-body-secondary">—</span>' ?></td>
          <td class="text-end text-nowrap">
            <?php if ($p['manual_hold']): ?><a class="btn btn-sm btn-outline-danger" href="<?= e(url('/prospects/' . $p['id'])) ?>">Review risk</a><?php else: ?>
            <form method="post" action="<?= e(url('/prospects/' . $p['id'] . '/decision')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-success" name="decision" value="approve">Approve</button></form>
            <form method="post" action="<?= e(url('/prospects/' . $p['id'] . '/decision')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="note" value="Not a fit after review"><button class="btn btn-sm btn-outline-danger" name="decision" value="reject" data-confirm="Reject as “Not a fit after review”?">Reject</button></form>
            <?php endif; ?>
          </td>
        </tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="tab-pane fade" id="tab-hist">
    <div class="card card-soft">
      <?php if (!$history): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'clock', 'heading' => 'No decisions yet']) ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>When</th><th>Item</th><th>Project</th><th>Party</th><th>Decision</th><th>By</th><th>Note</th></tr></thead>
        <tbody><?php foreach ($history as $h): ?><tr><td class="small text-nowrap"><?= e(fmt_dt($h['decided_at'])) ?></td><td class="small"><a href="<?= e(url($h['entity_type'] === 'prospect' ? '/prospects/' . $h['entity_id'] : '/outreach/messages/' . $h['entity_id'])) ?>"><?= e($h['entity_type']) ?> #<?= (int)$h['entity_id'] ?></a></td><td class="small"><?= e($h['project_name']) ?></td><td class="small"><?= e($h['required_party']) ?></td><td><?= badge($h['status']) ?></td><td class="small"><?= e($h['decided_by_name'] ?? '—') ?></td><td class="small"><?= e(str_limit($h['decision_note'], 60)) ?></td></tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
</div>
