<a class="small" href="<?= e(url('/admin/verifications')) ?>"><i class="bi bi-arrow-left"></i> Verifications</a>
<h1 class="h4 mt-2">Verification #<?= (int)$v['id'] ?></h1>
<div class="row g-3">
  <div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body">
    <?php if ($v['status'] === 'pending' && $v['document_path']): ?>
      <p class="small text-body-secondary"><i class="bi bi-eye me-1"></i>Opening the document is recorded in the audit log. It is deleted automatically when you decide.</p>
      <?php if (str_ends_with((string)$v['document_path'], '.pdf')): ?>
        <a class="btn btn-outline-primary" href="<?= e(url('/admin/verifications/' . (int)$v['id'] . '/document')) ?>" target="_blank" rel="noopener">Open PDF document</a>
      <?php else: ?>
        <img class="img-fluid rounded border" src="<?= e(url('/admin/verifications/' . (int)$v['id'] . '/document')) ?>" alt="Uploaded student document">
      <?php endif; ?>
    <?php else: ?><p class="text-body-secondary mb-0">Reviewed <?= e(fmt_dt($v['reviewed_at'])) ?> — <?= e($v['status']) ?>. The document has been deleted.</p><?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body small">
    <dl class="row mb-0">
      <dt class="col-5">Name on account</dt><dd class="col-7"><?= e($v['name']) ?></dd>
      <dt class="col-5">Display name</dt><dd class="col-7"><?= e($v['display_name'] ?? '—') ?></dd>
      <dt class="col-5">College (profile)</dt><dd class="col-7"><?= e($v['college_name'] ?? '—') ?></dd>
      <dt class="col-5">Document</dt><dd class="col-7"><?= e(label('document_type', $v['document_type'])) ?></dd>
      <dt class="col-5">Account created</dt><dd class="col-7"><?= e(fmt_dt($v['user_created'], 'd M Y')) ?></dd>
    </dl>
    <?php if ($v['status'] === 'pending'): ?>
    <hr><p class="mb-2">Approve only if the document clearly shows <strong>the same name</strong> and a <strong>current enrolment</strong> at a college.</p>
    <form method="post" action="<?= e(url('/admin/verifications/' . (int)$v['id'])) ?>"><?= csrf_field() ?>
      <label class="form-label" for="note">Note (required when rejecting — sent to the student)</label>
      <textarea class="form-control form-control-sm mb-2<?= invalid($errors, 'note') ?>" id="note" name="note" rows="2" maxlength="500"></textarea><?= field_error($errors, 'note') ?>
      <div class="d-flex gap-2"><button class="btn btn-success flex-fill" name="decision" value="approve">Approve</button><button class="btn btn-outline-danger flex-fill" name="decision" value="reject">Reject</button></div>
    </form>
    <?php endif; ?>
  </div></div></div>
</div>
