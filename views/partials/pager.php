<?php /** @var \Flatmates\Support\Paginator $pager */ if ($pager->pages > 1):
$q = $_GET; ?>
<nav aria-label="Pages" class="mt-4"><ul class="pagination justify-content-center flex-wrap">
<?php for ($i = max(1, $pager->page - 3); $i <= min($pager->pages, $pager->page + 3); $i++): $q['page'] = $i; ?>
  <li class="page-item<?= $i === $pager->page ? ' active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query($q)) ?>"><?= $i ?></a></li>
<?php endfor; ?>
</ul></nav>
<?php endif; ?>
