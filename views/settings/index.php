<?php
$field = static function (string $key) use ($values, $defs, $errors, $production): string {
    $d = $defs[$key];
    $name = 'settings[' . $key . ']';
    $id = 's_' . str_replace('.', '_', $key);
    $val = $values[$key];
    $invalid = isset($errors[$key]) ? ' is-invalid' : '';
    $fb = isset($errors[$key]) ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>' : '';
    switch ($d['type']) {
        case 'bool':
            return '<div class="form-check form-switch"><input type="hidden" name="' . e($name) . '" value="0"><input class="form-check-input" type="checkbox" role="switch" id="' . e($id) . '" name="' . e($name) . '" value="1"' . checked($val) . '><label class="form-check-label" for="' . e($id) . '">' . e($d['label']) . '</label></div>';
        case 'choice':
            $o = '';
            foreach ($d['choices'] as $c) {
                if ($c === 'log' && $production) {
                    continue;
                }
                $o .= '<option value="' . e($c) . '"' . selected($val, $c) . '>' . e($c) . '</option>';
            }
            return '<label class="form-label small" for="' . e($id) . '">' . e($d['label']) . '</label><select class="form-select form-select-sm' . $invalid . '" id="' . e($id) . '" name="' . e($name) . '">' . $o . '</select>' . $fb;
        case 'secret':
            $set = $val === '__SET__';
            return '<label class="form-label small" for="' . e($id) . '">' . e($d['label']) . '</label><input type="password" class="form-control form-control-sm' . $invalid . '" id="' . e($id) . '" name="' . e($name) . '" value="" autocomplete="new-password" placeholder="' . ($set ? '•••••••• (stored encrypted — leave blank to keep)' : 'not set') . '">' . ($set ? '<div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="clear[' . e($key) . ']" value="1" id="' . e($id) . '_c"><label class="form-check-label" for="' . e($id) . '_c">Clear stored value</label></div>' : '') . $fb;
        case 'timezone':
            $o = '';
            foreach (\DateTimeZone::listIdentifiers() as $tz) {
                $o .= '<option' . selected($val, $tz) . '>' . e($tz) . '</option>';
            }
            return '<label class="form-label small" for="' . e($id) . '">' . e($d['label']) . '</label><select class="form-select form-select-sm" id="' . e($id) . '" name="' . e($name) . '">' . $o . '</select>';
        case 'text':
            return '<label class="form-label small" for="' . e($id) . '">' . e($d['label']) . '</label><textarea class="form-control form-control-sm' . $invalid . '" rows="3" id="' . e($id) . '" name="' . e($name) . '">' . e($val) . '</textarea>' . $fb;
        case 'int':
            return '<label class="form-label small" for="' . e($id) . '">' . e($d['label']) . '</label><input type="number" class="form-control form-control-sm' . $invalid . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '"' . (isset($d['min']) ? ' min="' . (int)$d['min'] . '"' : '') . (isset($d['max']) ? ' max="' . (int)$d['max'] . '"' : '') . '>' . $fb;
        default:
            return '<label class="form-label small" for="' . e($id) . '">' . e($d['label']) . '</label><input type="' . ($d['type'] === 'email' ? 'email' : 'text') . '" class="form-control form-control-sm' . $invalid . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($val) . '">' . $fb;
    }
};
?>
<div class="page-head"><div><h1 class="h3 mb-0">Settings</h1><div class="small text-body-secondary">Secrets are encrypted at rest with the key in your private config file and are never displayed.</div></div></div>
<form method="post" action="<?= e(url('/settings')) ?>">
  <?= csrf_field() ?>
  <div class="row g-3">
    <?php foreach ($groups as $group => $keys): ?>
      <div class="col-xl-6">
        <div class="card card-soft h-100"><div class="card-header bg-transparent fw-semibold"><?= e($group) ?></div><div class="card-body row g-2">
          <?php foreach ($keys as $k): ?><div class="<?= in_array($defs[$k]['type'], ['bool', 'text'], true) ? 'col-12' : 'col-md-6' ?>"><?= $field($k) ?></div><?php endforeach; ?>
          <?php if ($group === 'SMTP'): ?><div class="col-12 small text-body-secondary">Use your hosting mailbox or a transactional provider. Credentials are only sent over TLS/SSL. The <code>log</code> driver (development only) writes .eml files to storage/mail.</div><?php endif; ?>
          <?php if ($group === 'Local AI model (optional)'): ?><div class="col-12 small text-body-secondary">Any OpenAI-compatible chat endpoint you run or are authorized to use (Ollama, llama.cpp, LM Studio, vLLM). Output is validated; on timeout or invalid output the offline templates are used.</div><?php endif; ?>
          <?php if ($group === 'Discovery & search API (optional)'): ?><div class="col-12 small text-body-secondary">Only official APIs or a SearXNG instance you are authorized to query. Search-engine result pages are never scraped. Note: Google’s Custom Search JSON API is closed to new customers and is discontinued on 1 Jan 2027 — prefer Brave Search API or SearXNG.</div><?php endif; ?>
        </div></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="sticky-save mt-3"><button class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Save settings</button></div>
</form>
<div class="d-flex flex-wrap gap-2 mt-3">
  <form method="post" action="<?= e(url('/settings/test-smtp')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary"><i class="bi bi-envelope"></i> Send SMTP test to my email</button></form>
  <form method="post" action="<?= e(url('/settings/test-ai')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary"><i class="bi bi-cpu"></i> Test local model</button></form>
</div>
