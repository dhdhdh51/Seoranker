<?php $u = $currentUser; $p = $currentPath ?? ''; $items = [
    ['/admin', 'speedometer2', 'Dashboard', true],
    ['/admin/reports', 'flag', 'Reports', $u->can('reports')],
    ['/admin/verifications', 'patch-check', 'Verifications', $u->can('verifications')],
    ['/admin/listings', 'house', 'Listings', $u->can('listings')],
    ['/admin/users', 'people', 'Users', $u->can('users')],
    ['/admin/locations', 'geo-alt', 'Cities & colleges', $u->isAdmin()],
    ['/admin/analytics', 'graph-up', 'Analytics', $u->isAdmin()],
    ['/admin/settings', 'gear', 'Settings', $u->isAdmin()],
    ['/admin/audit', 'journal-text', 'Audit log', $u->isAdmin()],
    ['/admin/health', 'heart-pulse', 'System health', $u->isAdmin()],
]; ?>
<div class="admin-nav border-bottom"><div class="container"><nav class="nav nav-underline flex-nowrap overflow-auto small" aria-label="Admin">
<?php foreach ($items as [$href, $icon, $text, $show]): if (!$show) continue; $on = $href === '/admin' ? $p === '/admin' : str_starts_with($p, $href); ?>
  <a class="nav-link text-nowrap<?= $on ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= e($icon) ?> me-1"></i><?= e($text) ?></a>
<?php endforeach; ?>
</nav></div></div>
