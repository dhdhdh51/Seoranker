<?php
/** Project filter dropdown (GET form). Vars: $projects (id=>label), $selected, $name ('project_id'), $allLabel */
$name = $name ?? 'project_id';
?>
<select name="<?= e($name) ?>" class="form-select form-select-sm" data-autosubmit aria-label="Project">
  <option value=""><?= e($allLabel ?? 'All projects') ?></option>
  <?php foreach ($projects as $id => $label): ?>
    <option value="<?= (int)$id ?>"<?= selected($selected ?? '', $id) ?>><?= e($label) ?></option>
  <?php endforeach; ?>
</select>
