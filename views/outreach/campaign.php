<?php
use BharatSeo\Support\Labels;
$c = $campaign;
?>
<div class="page-head">
  <div><div class="small text-body-secondary"><?= e($c['project_name']) ?> · <?= e(Labels::CAMPAIGN_TYPES[$c['type']] ?? $c['type']) ?></div><h1 class="h3 mb-0"><?= e($c['name']) ?> <?= badge($c['status']) ?></h1></div>
  <a class="btn btn-outline-secondary" href="<?= e(url('/exports/drafts', ['project_id' => $c['project_id'], 'campaign_id' => $c['id']])) ?>"><i class="bi bi-download"></i> Export CSV</a>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Messages</div>
      <?php if (!$messages): ?><?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'envelope', 'heading' => 'No drafts yet', 'text' => 'Select approved prospects on the right to generate personalised drafts.']) ?><?php else: ?>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Subject</th><th>Recipient</th><th>Status</th><th>Reply</th><th>Sent</th></tr></thead>
        <tbody><?php foreach ($messages as $m): ?><tr>
          <td><a href="<?= e(url('/outreach/messages/' . $m['id'])) ?>"><?= e(str_limit($m['subject'], 60)) ?></a><div class="small text-body-secondary"><?= e($m['prospect_domain']) ?><?= $m['message_type'] === 'follow_up' ? ' · follow-up ' . (int)$m['follow_up_number'] : '' ?></div></td>
          <td class="small"><?= $currentUser->isStaff() ? e($m['recipient_email'] ?: '— missing —') : '<span class="text-body-secondary">hidden</span>' ?></td>
          <td><?= badge($m['status']) ?></td><td><?= $m['status'] === 'sent' ? badge($m['reply_status']) : '' ?></td><td class="small text-nowrap"><?= e(fmt_dt($m['sent_at'])) ?></td>
        </tr><?php endforeach; ?></tbody>
      </table></div><?php endif; ?>
    </div>
  </div>
  <div class="col-xl-4">
    <?php if ($canManage): ?>
    <div class="card card-soft mb-3"><div class="card-header bg-transparent fw-semibold">Create drafts for approved prospects</div>
      <div class="card-body">
        <?php if (!$eligible): ?><p class="small text-body-secondary mb-0">No approved prospects without a draft in this campaign. Approve prospects in the review queue first.</p><?php else: ?>
        <form method="post" action="<?= e(url('/outreach/campaigns/' . $c['id'] . '/drafts')) ?>">
          <?= csrf_field() ?>
          <div class="list-scroll mb-2">
            <?php foreach ($eligible as $p): ?>
              <div class="form-check small"><input class="form-check-input" type="checkbox" name="prospect_ids[]" value="<?= (int)$p['id'] ?>" id="ep<?= (int)$p['id'] ?>"><label class="form-check-label text-break" for="ep<?= (int)$p['id'] ?>"><?= e($p['domain']) ?> · <?= (int)$p['quality_score'] ?> <?= $p['contact_email'] ? '' : '<span class="text-warning-emphasis">(no public email)</span>' ?></label></div>
            <?php endforeach; ?>
          </div>
          <?php if ($localAi): ?><div class="form-check small mb-2"><input class="form-check-input" type="checkbox" name="use_local_model" value="1" id="ulm"><label class="form-check-label" for="ulm">Refine with local model (falls back to template on any problem)</label></div><?php endif; ?>
          <button class="btn btn-primary btn-sm w-100">Generate drafts</button>
        </form><?php endif; ?>
      </div>
    </div>
    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Campaign settings</div><div class="card-body">
      <form method="post" action="<?= e(url('/outreach/campaigns/' . $c['id'])) ?>" class="row g-2 small">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label small">Name</label><input name="name" class="form-control form-control-sm" value="<?= e($c['name']) ?>" required></div>
        <div class="col-12"><label class="form-label small">Type</label><select name="type" class="form-select form-select-sm"><?php foreach (Labels::CAMPAIGN_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($c['type'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (['draft', 'active', 'paused', 'completed'] as $s): ?><option value="<?= $s ?>"<?= selected($c['status'], $s) ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-3"><label class="form-label small">F/U days</label><input type="number" name="follow_up_days" min="2" max="60" class="form-control form-control-sm" value="<?= (int)$c['follow_up_days'] ?>"></div>
        <div class="col-3"><label class="form-label small">Max F/U</label><input type="number" name="max_follow_ups" min="0" max="2" class="form-control form-control-sm" value="<?= (int)$c['max_follow_ups'] ?>"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="auto_drafts" value="1" id="ad2"<?= checked($c['auto_drafts']) ?>><label class="form-check-label" for="ad2">Auto-draft for newly approved prospects</label></div></div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save</button></div>
      </form>
    </div></div>
    <?php endif; ?>
  </div>
</div>
