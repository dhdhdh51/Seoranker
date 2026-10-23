<?php $c = $client ?? []; $v = static fn(string $k) => old($k, $c[$k] ?? ''); ?>
<div class="page-head"><h1 class="h3 mb-0"><?= e($title) ?></h1></div>
<div class="card card-soft"><div class="card-body">
<form method="post" action="<?= e(url($client ? '/clients/' . $client['id'] : '/clients')) ?>" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-md-6"><label class="form-label" for="name">Client name *</label><input id="name" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" name="name" value="<?= e($v('name')) ?>" required maxlength="160"><div class="invalid-feedback"><?= e($errors['name'] ?? '') ?></div></div>
  <div class="col-md-6"><label class="form-label" for="company">Company</label><input id="company" class="form-control" name="company" value="<?= e($v('company')) ?>" maxlength="190"></div>
  <div class="col-md-6"><label class="form-label" for="contact_email">Contact email</label><input id="contact_email" type="email" class="form-control<?= isset($errors['contact_email']) ? ' is-invalid' : '' ?>" name="contact_email" value="<?= e($v('contact_email')) ?>" maxlength="190"><div class="invalid-feedback"><?= e($errors['contact_email'] ?? '') ?></div></div>
  <div class="col-md-3"><label class="form-label" for="phone">Phone</label><input id="phone" class="form-control" name="phone" value="<?= e($v('phone')) ?>" maxlength="40"></div>
  <div class="col-md-3"><label class="form-label" for="status">Status</label><select id="status" class="form-select" name="status"><option value="active"<?= selected($v('status') ?: 'active', 'active') ?>>Active</option><option value="inactive"<?= selected($v('status'), 'inactive') ?>>Inactive</option></select></div>
  <div class="col-12"><label class="form-label" for="notes">Internal notes</label><textarea id="notes" class="form-control" name="notes" rows="3" maxlength="5000"><?= e($v('notes')) ?></textarea></div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Save client</button><a class="btn btn-outline-secondary" href="<?= e(url('/clients')) ?>">Cancel</a></div>
</form>
</div></div>
