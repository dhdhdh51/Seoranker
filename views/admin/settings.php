<?php $current = (string)($_GET['g'] ?? array_key_first($groups)); if (!isset($groups[$current])) { $current = array_key_first($groups); } ?>
<h1 class="h4 mb-3">Settings</h1>
<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto small"><?php foreach (array_keys($groups) as $g): ?><li class="nav-item"><a class="nav-link text-nowrap<?= $g === $current ? ' active' : '' ?>" href="<?= e(url('/admin/settings', ['g' => $g])) ?>"><?= e($g) ?></a></li><?php endforeach; ?></ul>
<?php if ($current === 'Email (SMTP)'): ?><div class="alert <?= $mailConfigured ? 'alert-success' : 'alert-warning' ?> small"><?= $mailConfigured ? 'Email is configured. Use "Send test email" to check delivery.' : 'Email is not configured — students cannot receive verification codes until you set the SMTP host and From email.' ?></div><?php endif; ?>
<?php if ($current === 'NoidaRankers connection'): ?><div class="alert alert-info small">Staff can sign in from the NoidaRankers platform (Flatmates admin link) when the same shared secret is set on both sides. Only existing moderator/Super Admin accounts with the same email can sign in this way.</div><?php endif; ?>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="card card-body border-0 shadow-sm"><?= csrf_field() ?><input type="hidden" name="group" value="<?= e($current) ?>">
<?php foreach ($groups[$current] as $key => $d): $field = str_replace('.', '__', $key); $val = $settings->get($key); ?>
  <div class="mb-3">
  <?php if ($d['type'] === 'bool'): ?>
    <input type="hidden" name="<?= e($field) ?>" value="0"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="<?= e($field) ?>" value="1" id="<?= e($field) ?>"<?= checked($val) ?>><label class="form-check-label" for="<?= e($field) ?>"><?= e($d['label']) ?></label></div>
  <?php else: ?>
    <label class="form-label small" for="<?= e($field) ?>"><?= e($d['label']) ?></label>
    <?php if ($d['type'] === 'choice'): ?><select class="form-select form-select-sm" id="<?= e($field) ?>" name="<?= e($field) ?>"><?php foreach ($d['choices'] as $c): ?><option value="<?= e($c) ?>"<?= selected($val, $c) ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <?php elseif ($d['type'] === 'text'): ?><textarea class="form-control form-control-sm<?= invalid($errors, $field) ?>" id="<?= e($field) ?>" name="<?= e($field) ?>" rows="3"><?= e($val) ?></textarea>
    <?php elseif ($d['type'] === 'secret'): ?><input class="form-control form-control-sm<?= invalid($errors, $field) ?>" type="password" id="<?= e($field) ?>" name="<?= e($field) ?>" autocomplete="new-password" placeholder="<?= $settings->has($key) ? '•••••••• (saved — type to replace)' : 'Not set' ?>">
    <?php elseif ($d['type'] === 'color'): ?><input class="form-control form-control-color<?= invalid($errors, $field) ?>" type="color" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($val) ?>">
    <?php else: ?><input class="form-control form-control-sm<?= invalid($errors, $field) ?>" type="<?= $d['type'] === 'int' ? 'number' : ($d['type'] === 'email' ? 'email' : ($d['type'] === 'url' ? 'url' : 'text')) ?>" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($val) ?>"<?= isset($d['min']) ? ' min="' . (int)$d['min'] . '" max="' . (int)$d['max'] . '"' : '' ?>>
    <?php endif; ?>
    <?= field_error($errors, $field) ?>
  <?php endif; ?>
  </div>
<?php endforeach; ?>
  <div><button class="btn btn-primary">Save <?= e($current) ?></button></div>
</form>
<?php if ($current === 'Email (SMTP)'): ?><form method="post" action="<?= e(url('/admin/settings/test-email')) ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-send"></i> Send test email to me</button></form><?php endif; ?>
