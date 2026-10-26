<?php
/** @var string $content */
/** @var \BharatSeo\Security\User $currentUser */
$u = $currentUser;
$path = $currentPath ?? '/';
$settings = app()->settings();
$nav = [
    ['/', 'speedometer2', 'Dashboard', true],
    ['/clients', 'buildings', 'Clients', $u->isStaff()],
    ['/projects', 'kanban', 'Projects', true],
    ['/discovery', 'search', 'Discovery', $u->isStaff() && $settings->moduleEnabled('discovery')],
    ['/prospects', 'list-check', 'Prospects', true],
    ['/approvals', 'check2-square', 'Approval queue', true],
    ['/outreach', 'envelope-paper', 'Outreach', $settings->moduleEnabled('outreach')],
    ['/backlinks', 'link-45deg', 'Backlink monitor', $settings->moduleEnabled('monitoring')],
    ['/backlinks/history', 'clock-history', 'Backlink history', $settings->moduleEnabled('monitoring')],
    ['/audits', 'clipboard2-pulse', 'Site audit', $settings->moduleEnabled('audit')],
    ['/reports', 'file-earmark-bar-graph', 'Reports & exports', $settings->moduleEnabled('reports')],
];
$adminNav = [
    ['/users', 'people', 'Users & roles'],
    ['/settings', 'gear', 'Settings'],
    ['/system/queue', 'cpu', 'Cron & queue'],
    ['/system/audit', 'shield-check', 'Audit log'],
    ['/system/install', 'box-seam', 'Install & upgrade'],
];
$isActive = static function (string $href) use ($path): bool {
    if ($href === '/') {
        return $path === '/';
    }
    if ($href === '/backlinks') {
        return $path === '/backlinks' || preg_match('#^/backlinks/\d+#', $path) === 1;
    }
    return str_starts_with($path, $href);
};
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? brand()) ?> · <?= e(brand()) ?></title>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="app-body">
<div class="app-shell">
  <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
      <a href="<?= e(url('/')) ?>" class="d-flex align-items-center gap-2 text-decoration-none">
        <span class="brand-mark"><?= e(brand_initial()) ?></span>
        <span><span class="brand-name"><?= e(brand()) ?></span><small class="d-block brand-sub">SEO &amp; Backlink Suite</small></span>
      </a>
      <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($nav as [$href, $icon, $label, $show]): if (!$show) { continue; } ?>
        <a class="sidebar-link<?= $isActive($href) ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= e($icon) ?>"></i><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
      <?php if ($u->isSuperAdmin()): ?>
        <div class="sidebar-section">Administration</div>
        <?php foreach ($adminNav as [$href, $icon, $label]): ?>
          <a class="sidebar-link<?= $isActive($href) ? ' active' : '' ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= e($icon) ?>"></i><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-foot small">v<?= e(APP_VERSION) ?> · <?= e(app()->timezone()->getName()) ?></div>
  </aside>

  <div class="app-main">
    <header class="app-topbar">
      <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open navigation"><i class="bi bi-list fs-4"></i></button>
      <div class="topbar-title text-truncate"><?= e($title ?? '') ?></div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Toggle dark mode" title="Toggle light/dark theme"><i class="bi bi-moon-stars"></i></button>
        <a href="<?= e(url('/notifications')) ?>" class="btn btn-icon position-relative" aria-label="Notifications">
          <i class="bi bi-bell"></i>
          <?php if (($unreadNotifications ?? 0) > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= (int)$unreadNotifications ?></span><?php endif; ?>
        </a>
        <div class="dropdown">
          <button class="btn btn-user dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($u->name, 0, 1))) ?></span>
            <span class="d-none d-md-inline"><?= e($u->name) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow">
            <li><span class="dropdown-item-text small text-body-secondary"><?= e($u->email) ?><br><?= e($u->roleLabel()) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
            <?php if (app()->make(\BharatSeo\Services\SsoService::class)->allowed($u)): ?>
              <li><a class="dropdown-item" href="<?= e(url('/sso/website')) ?>"><i class="bi bi-globe me-2"></i>Open website admin</a></li>
            <?php endif; ?>
            <?php if (app()->make(\BharatSeo\Services\SsoService::class)->flatmatesAllowed($u)): ?>
              <li><a class="dropdown-item" href="<?= e(url('/sso/flatmates')) ?>"><i class="bi bi-house-heart me-2"></i>Open Flatmates admin</a></li>
            <?php endif; ?>
            <li>
              <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form>
            </li>
          </ul>
        </div>
      </div>
    </header>
    <?php if (app()->config->get('app.demo_mode')): ?>
      <div class="alert alert-info rounded-0 mb-0 py-2 small text-center"><i class="bi bi-info-circle"></i> DEMO MODE — records prefixed “[DEMO]” are fictional sample data.</div>
    <?php endif; ?>
    <main class="app-content container-fluid">
      <?= \BharatSeo\Http\View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
      <?= $content ?>
    </main>
    <footer class="app-footer small text-body-secondary">
      <?= e(brand()) ?> · Quality scores are internal heuristics — not PageRank, DA or DR — and never a ranking guarantee.
    </footer>
  </div>
</div>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
