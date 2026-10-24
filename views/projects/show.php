<?php
use BharatSeo\Services\DashboardService;
$p = $project;
$o = $overview;
$id = (int)$p['id'];
?>
<div class="page-head">
  <div>
    <div class="small text-body-secondary"><a href="<?= e(url('/clients/' . $p['client_id'])) ?>"><?= e($p['client_name']) ?></a></div>
    <h1 class="h3 mb-0"><?= e($p['name']) ?> <?= badge($p['status']) ?></h1>
    <div class="small"><?= ext_link($p['website_url'], $p['domain']) ?> · created <?= e(fmt_date($p['created_at'])) ?></div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($canManage): ?>
      <a class="btn btn-outline-secondary" href="<?= e(url('/projects/' . $id . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a>
      <a class="btn btn-outline-primary" href="<?= e(url('/discovery', ['project_id' => $id])) ?>"><i class="bi bi-search"></i> Discover</a>
    <?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url('/reports/project/' . $id)) ?>"><i class="bi bi-file-earmark-bar-graph"></i> Report</a>
  </div>
</div>

<?php if ($p['approval_policy'] !== 'manager' && $o['client_approvers'] === 0): ?>
  <div class="alert alert-warning">This project requires <strong>client approval</strong> for outreach, but no client user has the “can approve” permission. Messages will wait in the approval queue.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php foreach ([['Prospects', $o['prospects'], url('/prospects', ['project_id' => $id])], ['Needs review', $o['needs_review'], url('/prospects', ['project_id' => $id, 'status' => 'needs_review'])], ['On manual hold', $o['held'], url('/prospects', ['project_id' => $id, 'risk' => 'hold'])], ['Approved', $o['approved'], url('/prospects', ['project_id' => $id, 'status' => 'approved'])], ['Emails sent', $o['messages_sent'], url('/outreach', ['project_id' => $id])], ['Active links', $o['links_active'], url('/backlinks', ['project_id' => $id])], ['Lost links', $o['links_lost'], url('/backlinks', ['project_id' => $id, 'lifecycle' => 'lost'])], ['Avg. score', $o['avg_score'] ?: '—', url('/prospects', ['project_id' => $id])]] as [$l, $n, $h]): ?>
    <div class="col-6 col-md-3"><a class="card kpi-mini text-decoration-none h-100" href="<?= e($h) ?>"><div class="card-body"><div class="kpi-label"><?= e($l) ?></div><div class="kpi-value"><?= is_int($n) ? num($n) : e($n) ?></div></div></a></div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card card-soft mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-body-secondary">Profile</h2>
      <dl class="row small mb-0">
        <dt class="col-sm-4">Niche</dt><dd class="col-sm-8"><?= e($p['niche'] ?: '—') ?></dd>
        <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(e($p['business_description'] ?: '—')) ?></dd>
        <dt class="col-sm-4">Countries / languages</dt><dd class="col-sm-8"><?= e($p['target_countries'] ?: '—') ?> · <?= e($p['target_languages'] ?: '—') ?></dd>
        <dt class="col-sm-4">Brand</dt><dd class="col-sm-8"><?= e($p['brand_name'] ?: '—') ?></dd>
        <dt class="col-sm-4">Preferred anchors</dt><dd class="col-sm-8"><?= nl2br(e($p['preferred_anchors'] ?: '—')) ?></dd>
        <dt class="col-sm-4">Keywords</dt><dd class="col-sm-8"><?php foreach ($keywords as $k): ?><span class="badge text-bg-light border me-1"><?= e($k) ?></span><?php endforeach; ?><?= $keywords ? '' : '—' ?></dd>
        <dt class="col-sm-4">Topics</dt><dd class="col-sm-8"><?php foreach ($topics as $k): ?><span class="badge text-bg-light border me-1"><?= e($k) ?></span><?php endforeach; ?><?= $topics ? '' : '—' ?></dd>
        <dt class="col-sm-4">Outreach identity</dt><dd class="col-sm-8"><?= e($p['outreach_from_name'] ?: '—') ?> &lt;<?= e($p['contact_email'] ?: 'no reply-to') ?>&gt;</dd>
        <dt class="col-sm-4">Approval policy</dt><dd class="col-sm-8"><?= e(['manager' => 'SEO manager approves', 'client' => 'Client approves', 'manager_and_client' => 'Manager and client approve'][$p['approval_policy']]) ?></dd>
        <dt class="col-sm-4">Exclusions</dt><dd class="col-sm-8"><?= nl2br(e($p['exclusions'] ?: '—')) ?></dd>
      </dl>
    </div></div>
    <div class="card card-soft mb-3">
      <div class="card-header bg-transparent d-flex justify-content-between"><span class="fw-semibold">Target landing pages</span>
        <?php if ($canManage): ?><form method="post" action="<?= e(url('/projects/' . $id . '/sitemap')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary" <?= $p['verified_at'] ? '' : 'disabled title="Verify ownership first"' ?>><i class="bi bi-diagram-3"></i> Import from sitemap.xml</button></form><?php endif; ?></div>
      <?php if (!$targets): ?><div class="card-body small text-body-secondary">No target pages yet. Add them in Edit.</div><?php else: ?>
      <ul class="list-group list-group-flush small"><?php foreach ($targets as $t): ?><li class="list-group-item text-truncate"><?= ext_link($t['url'], null, 90) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card card-soft mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-body-secondary">Site ownership verification</h2>
      <?php if ($p['verified_at']): ?>
        <div class="alert alert-success small mb-2"><i class="bi bi-patch-check"></i> Verified via <?= e($p['verification_method']) ?> on <?= e(fmt_dt($p['verified_at'])) ?>.</div>
      <?php else: ?>
        <p class="small">Required before any feature that crawls or acts on the client’s own website (e.g. sitemap import). Choose one method:</p>
      <?php endif; ?>
      <ol class="small ps-3">
        <li class="mb-2"><strong>File:</strong> upload a file at <code class="text-break"><?= e($verification['file']['url']) ?></code> containing <code class="text-break"><?= e($verification['file']['content']) ?></code></li>
        <li class="mb-2"><strong>Meta tag</strong> on the homepage: <code class="text-break"><?= e($verification['meta']) ?></code></li>
        <li><strong>DNS TXT</strong> on <code><?= e($verification['dns']['host']) ?></code>: <code class="text-break"><?= e($verification['dns']['value']) ?></code></li>
      </ol>
      <?php if ($canManage): ?>
        <form method="post" action="<?= e(url('/projects/' . $id . '/verify')) ?>" class="d-flex gap-2">
          <?= csrf_field() ?>
          <select name="method" class="form-select form-select-sm" aria-label="Verification method"><option value="file">File</option><option value="meta">Meta tag</option><option value="dns">DNS TXT</option></select>
          <button class="btn btn-sm btn-primary text-nowrap">Verify now</button>
        </form>
      <?php endif; ?>
    </div></div>

    <?php if ($canManage): ?>
    <div class="card card-soft mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-body-secondary">Project status</h2>
      <form method="post" action="<?= e(url('/projects/' . $id . '/status')) ?>" class="d-flex gap-2" data-confirm="Change project status? Pausing or archiving cancels pending jobs for this project.">
        <?= csrf_field() ?>
        <select name="status" class="form-select form-select-sm" aria-label="Status"><?php foreach (['active', 'paused', 'archived'] as $s): ?><option value="<?= $s ?>"<?= selected($p['status'], $s) ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-outline-primary">Update</button>
      </form>
      <hr>
      <form method="post" action="<?= e(url('/projects/' . $id . '/settings')) ?>" class="small">
        <?= csrf_field() ?>
        <input type="hidden" name="auto_search" value="0">
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="as" name="auto_search" value="1"<?= checked(app()->settings()->project($id, 'auto_search') === '1') ?>><label class="form-check-label" for="as">Scheduled daily search-API discovery (uses the configured provider quota)</label></div>
        <button class="btn btn-sm btn-outline-secondary">Save automation</button>
      </form>
    </div></div>
    <?php endif; ?>

    <?php if ($currentUser->isSuperAdmin()): ?>
    <div class="card card-soft mb-3" id="client-access"><div class="card-body">
      <h2 class="h6 text-uppercase text-body-secondary">Client portal access</h2>
      <p class="small text-body-secondary mb-2">People added here sign in to this platform and see only <strong><?= e($p['client_name']) ?></strong>’s projects. “Can approve” lets them approve outreach when the policy needs client approval.</p>
      <ul class="list-unstyled small mb-2">
        <?php foreach ($clientUsers as $cu): ?>
          <li class="d-flex justify-content-between align-items-center border-bottom py-1">
            <span><?= e($cu['name']) ?> <span class="text-body-secondary">&lt;<?= e($cu['email']) ?>&gt;</span> <?= $cu['can_approve'] ? badge('approved', 'approve') : '' ?> <?= $cu['can_export'] ? badge('info', 'export') : '' ?></span>
            <form method="post" action="<?= e(url('/projects/' . $id . '/client-users/' . $cu['user_id'] . '/remove')) ?>" data-confirm="Remove this person's access?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
          </li>
        <?php endforeach; ?>
        <?php if (!$clientUsers): ?><li class="text-body-secondary">No client users yet.</li><?php endif; ?>
      </ul>
      <ul class="nav nav-pills nav-sm small mb-2" role="tablist">
        <li class="nav-item"><button class="nav-link active py-1" data-bs-toggle="tab" data-bs-target="#cu-new" type="button">New client user</button></li>
        <?php if ($clientUserOptions): ?><li class="nav-item"><button class="nav-link py-1" data-bs-toggle="tab" data-bs-target="#cu-existing" type="button">Existing user</button></li><?php endif; ?>
      </ul>
      <div class="tab-content">
        <form class="tab-pane fade show active row g-2" id="cu-new" method="post" action="<?= e(url('/projects/' . $id . '/client-users')) ?>" autocomplete="off">
          <?= csrf_field() ?>
          <div class="col-6"><input name="name" class="form-control form-control-sm" placeholder="Name" required maxlength="120"></div>
          <div class="col-6"><input name="email" type="email" class="form-control form-control-sm" placeholder="Email" required maxlength="190"></div>
          <div class="col-12"><input name="password" type="password" class="form-control form-control-sm" placeholder="Initial password (10+ chars, letters + number)" required minlength="10" autocomplete="new-password"></div>
          <div class="col-6"><div class="form-check small"><input class="form-check-input" type="checkbox" name="can_approve" value="1" id="cua"><label class="form-check-label" for="cua">Can approve</label></div></div>
          <div class="col-6"><div class="form-check small"><input class="form-check-input" type="checkbox" name="can_export" value="1" id="cue" checked><label class="form-check-label" for="cue">Can export</label></div></div>
          <div class="col-12"><button class="btn btn-sm btn-primary">Create &amp; give access</button></div>
        </form>
        <?php if ($clientUserOptions): ?>
        <form class="tab-pane fade row g-2" id="cu-existing" method="post" action="<?= e(url('/projects/' . $id . '/client-users')) ?>">
          <?= csrf_field() ?>
          <div class="col-12"><select name="user_id" class="form-select form-select-sm" required><option value="">Client-role user…</option><?php foreach ($clientUserOptions as $uid => $l): ?><option value="<?= (int)$uid ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><div class="form-check small"><input class="form-check-input" type="checkbox" name="can_approve" value="1" id="cua2"><label class="form-check-label" for="cua2">Can approve</label></div></div>
          <div class="col-6"><div class="form-check small"><input class="form-check-input" type="checkbox" name="can_export" value="1" id="cue2" checked><label class="form-check-label" for="cue2">Can export</label></div></div>
          <div class="col-12"><button class="btn btn-sm btn-primary">Give access</button></div>
        </form>
        <?php endif; ?>
      </div>
    </div></div>
    <?php endif; ?>

    <div class="card card-soft mb-3"><div class="card-body d-flex justify-content-between align-items-center gap-2">
      <div><h2 class="h6 text-uppercase text-body-secondary mb-1">Site audit</h2>
        <div class="small"><?php if ($lastAudit): ?>Last: <?= $lastAudit['score'] !== null ? '<strong>' . (int)$lastAudit['score'] . '/100</strong>' : badge($lastAudit['status']) ?> · <?= e(fmt_dt($lastAudit['finished_at'])) ?><?php else: ?>No audit yet.<?php endif; ?></div></div>
      <div class="d-flex gap-2">
        <?php if ($lastAudit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/audits/' . $lastAudit['id'])) ?>">View</a><?php endif; ?>
        <?php if ($canManage): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/audits', ['project_id' => $id])) ?>"><i class="bi bi-clipboard2-pulse"></i> Run audit</a><?php endif; ?>
      </div>
    </div></div>

    <?php if ($currentUser->isStaff()): ?>
    <div class="card card-soft mb-3"><div class="card-body">
      <h2 class="h6 text-uppercase text-body-secondary">Team</h2>
      <?php if (!$members): ?><p class="small text-body-secondary">No SEO managers assigned. Super Admins always have access.</p><?php endif; ?>
      <ul class="list-unstyled small mb-2">
        <?php foreach ($members as $m): ?>
          <li class="d-flex justify-content-between align-items-center border-bottom py-1"><span><?= e($m['name']) ?> · <span class="text-body-secondary"><?= e($m['role']) ?></span></span>
          <?php if ($currentUser->isSuperAdmin()): ?><form method="post" action="<?= e(url('/projects/' . $id . '/members/' . $m['user_id'] . '/remove')) ?>" data-confirm="Remove this member?"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($currentUser->isSuperAdmin() && $managerOptions): ?>
        <form method="post" action="<?= e(url('/projects/' . $id . '/members')) ?>" class="d-flex gap-2">
          <?= csrf_field() ?>
          <select name="user_id" class="form-select form-select-sm" required aria-label="Manager"><option value="">SEO manager…</option><?php foreach ($managerOptions as $uid => $l): ?><option value="<?= (int)$uid ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <select name="role" class="form-select form-select-sm w-auto" aria-label="Role"><option value="manager">Manager</option><option value="viewer">Viewer</option></select>
          <button class="btn btn-sm btn-primary">Add</button>
        </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card card-soft"><div class="card-header bg-transparent fw-semibold">Recent activity</div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($activity as $a): ?><li class="list-group-item d-flex justify-content-between"><span><?= e(DashboardService::actionLabel($a['action'])) ?></span><span class="text-body-secondary"><?= e(fmt_dt($a['created_at'])) ?></span></li><?php endforeach; ?>
        <?php if (!$activity): ?><li class="list-group-item text-body-secondary">No activity yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
