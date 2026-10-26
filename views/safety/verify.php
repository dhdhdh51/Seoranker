<?php use Flatmates\Support\Options; ?>
<div class="row justify-content-center"><div class="col-lg-8">
<h1 class="h4">Verify you are a student</h1>
<p class="text-body-secondary">Verified students get a <i class="bi bi-patch-check-fill text-primary"></i> badge and rank higher in discovery. Verification helps, but it is not a guarantee — always follow our <a href="<?= e(url('/safety')) ?>">safety tips</a>.</p>
<?php if ($verified): ?>
  <div class="alert alert-success"><i class="bi bi-patch-check-fill me-1"></i>You are verified.</div>
<?php else: ?>
<div class="row g-3">
  <div class="col-md-6"><div class="card h-100 shadow-sm border-0"><div class="card-body">
    <h2 class="h6"><i class="bi bi-envelope-at me-1"></i>Option 1 · College email (instant)</h2>
    <p class="small text-body-secondary">We email a code to your official college address. Your college email is never shown to anyone.</p>
    <?php if ($pendingEmail): ?>
      <form method="post" action="<?= e(url('/verify/email/confirm')) ?>"><?= csrf_field() ?>
        <label class="form-label small" for="ccode">Code sent to <?= e($pendingEmail) ?></label>
        <input class="form-control otp-input<?= invalid($errors, 'code') ?>" id="ccode" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required><?= field_error($errors, 'code') ?>
        <button class="btn btn-primary w-100 mt-2">Confirm</button></form>
      <p class="small mt-2 mb-0 text-body-secondary">Wrong address? Enter it again below.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/verify/email')) ?>" class="mt-2"><?= csrf_field() ?>
      <label class="form-label small" for="cemail">College email</label>
      <input class="form-control<?= invalid($errors, 'college_email') . invalid($errors, 'otp') ?>" type="email" id="cemail" name="college_email" placeholder="you@college.edu.in" required><?= field_error($errors, 'college_email') ?><?= field_error($errors, 'otp') ?>
      <button class="btn btn-outline-primary w-100 mt-2">Send code</button></form>
    <details class="small mt-3"><summary>Supported college domains</summary><ul class="mt-2 mb-0"><?php foreach ($domains as $d): ?><li><?= e($d['name']) ?> — <code>@<?= e($d['email_domain']) ?></code></li><?php endforeach; ?></ul><p class="mt-2 mb-0">College not listed? Use option 2.</p></details>
  </div></div></div>
  <div class="col-md-6"><div class="card h-100 shadow-sm border-0"><div class="card-body">
    <h2 class="h6"><i class="bi bi-card-heading me-1"></i>Option 2 · Student document</h2>
    <p class="small text-body-secondary">Upload your college ID card or admission/fee letter. A trained moderator checks it, then <strong>the file is permanently deleted</strong>. You may cover your ID number and photo except your name and college.</p>
    <form method="post" action="<?= e(url('/verify/document')) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <label class="form-label small" for="dtype">Document type</label>
      <select class="form-select mb-2<?= invalid($errors, 'document_type') ?>" id="dtype" name="document_type" required><option value="">Choose…</option><?php foreach (Options::GROUPS['document_type'] as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
      <label class="form-label small" for="doc">Photo or PDF (max 6 MB)</label>
      <input class="form-control<?= invalid($errors, 'document') . invalid($errors, 'photo') ?>" type="file" id="doc" name="document" accept="image/jpeg,image/png,image/webp,application/pdf" required><?= field_error($errors, 'document') ?><?= field_error($errors, 'photo') ?>
      <button class="btn btn-outline-primary w-100 mt-2">Upload for review</button></form>
  </div></div></div>
</div>
<?php endif; ?>
<?php if ($history): ?>
  <h2 class="h6 mt-4">History</h2>
  <ul class="list-group small"><?php foreach ($history as $h): ?>
    <li class="list-group-item d-flex justify-content-between"><span><?= e($h['method'] === 'college_email' ? 'College email' : label('document_type', $h['document_type'])) ?><?= $h['note'] && $h['status'] === 'rejected' ? ' — ' . e($h['note']) : '' ?></span><span class="badge <?= $h['status'] === 'approved' ? 'text-bg-success' : ($h['status'] === 'pending' ? 'text-bg-warning' : 'text-bg-danger') ?>"><?= e($h['status']) ?></span></li>
  <?php endforeach; ?></ul>
<?php endif; ?>
</div></div>
