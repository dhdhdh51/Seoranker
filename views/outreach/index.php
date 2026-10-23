<?php use BharatSeo\Support\Labels; ?>
<div class="page-head">
  <h1 class="h3 mb-0">Outreach campaigns</h1>
  <div class="d-flex gap-2 flex-wrap">
    <form method="get"><?= \BharatSeo\Http\View::partial('partials/project_select', ['projects' => $projects, 'selected' => $projectId]) ?></form>
    <a class="btn btn-outline-secondary" href="<?= e(url('/exports/drafts', ['project_id' => $projectId])) ?>"><i class="bi bi-download"></i> Export drafts CSV</a>
    <?php if ($currentUser->isStaff()): ?>
      <a class="btn btn-outline-secondary" href="<?= e(url('/outreach/suppressions')) ?>"><i class="bi bi-slash-circle"></i> Suppression list</a>
      <a class="btn btn-primary" href="<?= e(url('/outreach/campaigns/new', ['project_id' => $projectId])) ?>"><i class="bi bi-plus-lg"></i> New campaign</a>
    <?php endif; ?>
  </div>
</div>
<?php if (!$smtp && $currentUser->isStaff()): ?>
  <div class="alert alert-warning small"><i class="bi bi-envelope-exclamation"></i> SMTP is not configured, so approved messages will not be sent automatically. You can still draft, approve, copy to clipboard and export drafts to send from your own mailbox.</div>
<?php endif; ?>
<div class="card card-soft mb-3">
<?php if (!$campaigns): ?>
  <?= \BharatSeo\Http\View::partial('partials/empty', ['icon' => 'envelope-paper', 'heading' => 'No campaigns yet', 'text' => 'Campaigns group personalised drafts for approved prospects. Nothing is sent without approval.', 'actionUrl' => $currentUser->isStaff() ? url('/outreach/campaigns/new') : null, 'actionLabel' => 'Create campaign']) ?>
<?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Campaign</th><th>Project</th><th>Type</th><th class="text-end">Messages</th><th class="text-end">Sent</th><th class="text-end">Replies</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($campaigns as $c): ?>
      <tr><td><a class="fw-semibold" href="<?= e(url('/outreach/campaigns/' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td class="small"><?= e($c['project_name']) ?></td><td class="small"><?= e(Labels::CAMPAIGN_TYPES[$c['type']] ?? $c['type']) ?></td><td class="text-end"><?= num((int)$c['message_count']) ?></td><td class="text-end"><?= num((int)$c['sent_count']) ?></td><td class="text-end"><?= num((int)$c['reply_count']) ?></td><td><?= badge($c['status']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
</div>
<?php if ($recent): ?>
<div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Recent messages</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Subject</th><th>Prospect</th><th>Campaign</th><th>Status</th><th>Updated</th></tr></thead>
    <tbody><?php foreach ($recent as $m): ?><tr><td><a href="<?= e(url('/outreach/messages/' . $m['id'])) ?>"><?= e(str_limit($m['subject'], 70)) ?></a><?= $m['message_type'] === 'follow_up' ? ' <span class="badge text-bg-light border">follow-up ' . (int)$m['follow_up_number'] . '</span>' : '' ?></td><td class="small"><?= e($m['prospect_domain']) ?></td><td class="small"><?= e($m['campaign_name']) ?></td><td><?= badge($m['status']) ?></td><td class="small text-nowrap"><?= e(fmt_dt($m['updated_at'])) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
