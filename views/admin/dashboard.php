<?php $s = $stats; $card = static fn(string $label, $v, string $icon, string $href = '', string $tone = '') => '<div class="col-6 col-md-4 col-xl-3"><' . ($href ? 'a href="' . e(url($href)) . '"' : 'div') . ' class="card stat-card border-0 shadow-sm h-100 text-decoration-none ' . e($tone) . '"><div class="card-body"><div class="small text-body-secondary"><i class="bi bi-' . e($icon) . ' me-1"></i>' . e($label) . '</div><div class="fs-3 fw-bold">' . e((string)$v) . '</div></div></' . ($href ? 'a' : 'div') . '></div>'; ?>
<h1 class="h4 mb-3">Dashboard</h1>
<?php if ($health): ?><div class="alert alert-warning small"><strong>Attention:</strong> <?php foreach ($health as $h): ?><div><i class="bi bi-exclamation-triangle me-1"></i><?= e($h[0]) ?>: <?= e($h[1]) ?></div><?php endforeach; ?><a href="<?= e(url('/admin/health')) ?>">System health</a></div><?php endif; ?>
<div class="row g-3 mb-4">
  <?= $card('Active students', $s['students'], 'people', $currentUser->can('users') ? '/admin/users' : '') ?>
  <?= $card('New this week', $s['new_week'], 'person-plus') ?>
  <?= $card('Active this week', $s['active_week'], 'activity') ?>
  <?= $card('Verified students', $s['verified'], 'patch-check') ?>
  <?= $card('Open reports', $s['open_reports'] . ($s['serious_reports'] ? ' (' . $s['serious_reports'] . ' serious)' : ''), 'flag', $currentUser->can('reports') ? '/admin/reports' : '', $s['serious_reports'] ? 'stat-danger' : '') ?>
  <?= $card('Verifications waiting', $s['pending_verifications'], 'hourglass-split', $currentUser->can('verifications') ? '/admin/verifications' : '') ?>
  <?= $card('Listings awaiting review', $s['listings_pending'], 'house-exclamation', $currentUser->can('listings') ? '/admin/listings?status=pending' : '') ?>
  <?= $card('Live listings', $s['listings_live'], 'house-check') ?>
  <?= $card('Matches (7 days)', $s['matches_week'], 'heart') ?>
  <?= $card('Messages (7 days)', $s['messages_week'], 'chat-dots') ?>
  <?= $card('Emails pending / failed', $s['mail_pending'] . ' / ' . $s['mail_failed'], 'envelope-exclamation', $currentUser->isAdmin() ? '/admin/health' : '', $s['mail_failed'] ? 'stat-danger' : '') ?>
</div>
<div class="row g-3">
  <?php if ($recentReports): ?><div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Reports to review</div><ul class="list-group list-group-flush small"><?php foreach ($recentReports as $r): ?><li class="list-group-item"><a href="<?= e(url('/admin/reports/' . (int)$r['id'])) ?>"><?= e(label('report_reason', $r['reason'])) ?></a> · <?= e($r['target_type']) ?> <?= $r['severity'] === 'serious' ? '<span class="badge text-bg-danger">serious</span>' : '' ?> <span class="text-body-secondary"><?= e(ago($r['created_at'])) ?></span></li><?php endforeach; ?></ul></div></div><?php endif; ?>
  <?php if ($pendingVerifications): ?><div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Verifications</div><ul class="list-group list-group-flush small"><?php foreach ($pendingVerifications as $v): ?><li class="list-group-item"><a href="<?= e(url('/admin/verifications/' . (int)$v['id'])) ?>"><?= e($v['display_name'] ?? $v['name']) ?></a> · <?= e(label('document_type', $v['document_type'])) ?> <span class="text-body-secondary"><?= e(ago($v['created_at'])) ?></span></li><?php endforeach; ?></ul></div></div><?php endif; ?>
  <?php if ($pendingListings): ?><div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-header bg-transparent fw-semibold">Listings to review</div><ul class="list-group list-group-flush small"><?php foreach ($pendingListings as $l): ?><li class="list-group-item"><a href="<?= e(url('/rooms/' . $l['slug'])) ?>"><?= e(str_limit($l['title'], 50)) ?></a> <span class="text-body-secondary"><?= e(ago($l['created_at'])) ?></span></li><?php endforeach; ?></ul></div></div><?php endif; ?>
</div>
