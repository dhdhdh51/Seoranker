<?php
/** @var string[] $keys  trust badge keys (from TrustService) */
$defs = \Flatmates\Services\TrustService::BADGES;
$keys = array_values(array_filter($keys ?? [], static fn($k) => isset($defs[$k])));
$onPhoto = !empty($onPhoto);
if (!$keys) { return; }
?>
<div class="tbadges<?= !empty($class) ? ' ' . e($class) : '' ?>">
<?php foreach ($keys as $k): $d = $defs[$k]; ?>
  <a class="tbadge tbadge-<?= e($d['tone']) ?><?= $onPhoto ? ' tbadge-on-photo' : '' ?>" href="<?= e(url('/safety')) ?>#badges" title="<?= e($d['means'] . ' ' . $d['not']) ?>"><i class="bi bi-<?= e($d['icon']) ?>" aria-hidden="true"></i><?= e($d['label']) ?></a>
<?php endforeach; ?>
</div>
