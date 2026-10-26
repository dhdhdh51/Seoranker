<?php
use Flatmates\Services\TrustService;
use Flatmates\Support\Options;

$pendingDoc = (bool)array_filter($history, static fn($h) => $h['method'] === 'document' && $h['status'] === 'pending');
$state = static function (string $k) use ($badges, $pendingDoc): string {
    if (in_array($k, $badges, true)) { return 'ok'; }
    return $k === 'student_id' && $pendingDoc ? 'pending' : 'off';
};
?>
<div class="page-head"><div><span class="eyebrow"><i class="bi bi-patch-check" aria-hidden="true"></i> Verification centre</span><h1 class="mt-2">Show students they can trust you</h1><p>Verified students rank higher in Discover. Each badge shows exactly which check was done.</p></div></div>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="surface p-4">
      <h2 class="h6 mb-2">Your badges</h2>
      <?php foreach (['email_verified', 'college_email', 'student_id'] as $k): $d = TrustService::BADGES[$k]; $st = $state($k); ?>
        <div class="trust-row">
          <span class="trust-ico <?= $st ?>"><i class="bi bi-<?= $st === 'ok' ? 'check-lg' : ($st === 'pending' ? 'hourglass-split' : $d['icon']) ?>" aria-hidden="true"></i></span>
          <div><div class="d-flex justify-content-between gap-2"><strong><?= e($d['label']) ?></strong><span class="small <?= $st === 'ok' ? 'text-success' : 'text-body-secondary' ?>" <?= $st === 'pending' ? 'style="color:var(--fm-pending)!important"' : '' ?>><?= $st === 'ok' ? 'Verified' : ($st === 'pending' ? 'In review' : 'Not yet') ?></span></div>
            <div class="small text-body-secondary"><?= e($d['means']) ?></div></div>
        </div>
      <?php endforeach; ?>
      <p class="small text-body-secondary mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Badges reduce risk but never guarantee identity or safety. <a href="<?= e(url('/safety')) ?>#badges">What each badge doesn't prove</a></p>
    </div>
    <?php if ($history): ?>
      <div class="surface p-4 mt-4"><h2 class="h6">History</h2>
        <ul class="list-unstyled small mb-0 d-grid gap-2"><?php foreach ($history as $h): ?>
          <li class="d-flex justify-content-between gap-2"><span><?= e($h['method'] === 'college_email' ? 'College email' : label('document_type', $h['document_type'])) ?> · <span class="text-body-secondary"><?= e(ago($h['created_at'])) ?></span><?= $h['note'] && $h['status'] === 'rejected' ? '<br><span class="text-danger">' . e($h['note']) . '</span>' : '' ?></span>
            <span class="tbadge tbadge-<?= $h['status'] === 'approved' ? 'success' : ($h['status'] === 'pending' ? 'pending' : 'neutral') ?>"><?= e(ucfirst($h['status'])) ?></span></li>
        <?php endforeach; ?></ul></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-7">
    <?php if ($verified): ?>
      <div class="surface p-4"><div class="notice notice-info"><i class="bi bi-check-circle text-success" aria-hidden="true"></i><div><strong>You're a verified student.</strong> Your badge appears on your profile, swipe card and listings.</div></div></div>
    <?php else: ?>
    <div class="surface p-4 mb-4">
      <div class="d-flex justify-content-between align-items-start gap-2"><h2 class="h5 mb-1"><i class="bi bi-envelope-at text-primary me-1" aria-hidden="true"></i>Option 1 · College email</h2><span class="tbadge tbadge-success">Instant</span></div>
      <p class="small text-body-secondary">We email a 6-digit code to your official college address. It's never shown to anyone and can be linked to only one account.</p>
      <?php if ($pendingEmail): ?>
        <form method="post" action="<?= e(url('/verify/email/confirm')) ?>" class="mb-3" data-loading><?= csrf_field() ?>
          <label class="form-label" for="ccode">Code sent to <?= e($pendingEmail) ?></label>
          <div class="d-flex gap-2"><input class="form-control otp-input<?= invalid($errors, 'code') ?>" id="ccode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required><button class="btn btn-primary">Confirm</button></div><?= field_error($errors, 'code') ?>
        </form>
        <p class="small text-body-secondary">Wrong address or no email? Enter it again below.</p>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/verify/email')) ?>" data-loading><?= csrf_field() ?>
        <label class="form-label" for="cemail">College email address</label>
        <div class="d-flex gap-2 flex-wrap flex-sm-nowrap"><input class="form-control<?= invalid($errors, 'college_email') . invalid($errors, 'otp') ?>" type="email" id="cemail" name="college_email" placeholder="you@college.edu.in" autocomplete="email" required><button class="btn btn-outline-primary text-nowrap">Send code</button></div>
        <?= field_error($errors, 'college_email') ?><?= field_error($errors, 'otp') ?>
      </form>
      <details class="small mt-3"><summary>Supported college domains (<?= count($domains) ?>)</summary><ul class="mt-2 mb-0 columns-2"><?php foreach ($domains as $d): ?><li><?= e($d['name']) ?> — <code>@<?= e($d['email_domain']) ?></code></li><?php endforeach; ?></ul><p class="mt-2 mb-0">College not listed? Use option 2.</p></details>
    </div>
    <div class="surface p-4">
      <div class="d-flex justify-content-between align-items-start gap-2"><h2 class="h5 mb-1"><i class="bi bi-person-vcard text-primary me-1" aria-hidden="true"></i>Option 2 · Student document</h2><span class="tbadge tbadge-neutral">Reviewed by a person</span></div>
      <?php if ($pendingDoc): ?>
        <div class="notice notice-safety mt-2"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div>Your document is waiting for a moderator. You'll get a notification when it's reviewed.</div></div>
      <?php else: ?>
      <ul class="small text-body-secondary mb-3">
        <li>Upload your college ID card, admission letter or fee receipt.</li>
        <li>You may cover your ID number, photo and address — we only need your <strong>name</strong>, <strong>college</strong> and a <strong>current date or session</strong>.</li>
        <li>The file is stored privately (never on the public website), seen only by authorised moderators, every view is logged, and it's <strong>permanently deleted</strong> right after the decision.</li>
      </ul>
      <form method="post" action="<?= e(url('/verify/document')) ?>" enctype="multipart/form-data" data-loading><?= csrf_field() ?>
        <div class="row g-2">
          <div class="col-sm-5"><label class="form-label" for="dtype">Document type</label><select class="form-select<?= invalid($errors, 'document_type') ?>" id="dtype" name="document_type" required><option value="">Choose…</option><?php foreach (Options::GROUPS['document_type'] as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-7"><label class="form-label" for="doc">Photo or PDF (max 6 MB)</label><input class="form-control<?= invalid($errors, 'document') . invalid($errors, 'photo') ?>" type="file" id="doc" name="document" accept="image/jpeg,image/png,image/webp,application/pdf" required></div>
        </div>
        <?= field_error($errors, 'document') ?><?= field_error($errors, 'photo') ?>
        <button class="btn btn-primary mt-3"><i class="bi bi-upload" aria-hidden="true"></i> Upload securely for review</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
