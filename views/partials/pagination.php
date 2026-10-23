<?php
/** @var \BharatSeo\Support\Paginator $pager */
if ($pager->pages <= 1) {
    echo '<div class="small text-body-secondary">' . num($pager->total) . ' result(s)</div>';
    return;
}
$q = $_GET;
$link = static function (int $p) use ($q): string {
    $q['page'] = $p;
    $q = array_filter($q, static fn($v) => is_scalar($v) && $v !== '');
    return '?' . http_build_query($q);
};
$start = max(1, $pager->page - 2);
$end = min($pager->pages, $pager->page + 2);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
  <div class="small text-body-secondary">Showing <?= num($pager->from()) ?>–<?= num($pager->to()) ?> of <?= num($pager->total) ?></div>
  <nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">
    <li class="page-item<?= $pager->page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link($pager->page - 1)) ?>">‹</a></li>
    <?php for ($i = $start; $i <= $end; $i++): ?>
      <li class="page-item<?= $i === $pager->page ? ' active' : '' ?>"><a class="page-link" href="<?= e($link($i)) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item<?= $pager->page >= $pager->pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link($pager->page + 1)) ?>">›</a></li>
  </ul></nav>
</div>
