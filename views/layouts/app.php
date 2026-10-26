<?php
/** @var string $content */
$u = $currentUser ?? null;
$path = $currentPath ?? '/';
$active = static fn(string $p): string => ($path === $p || ($p !== '/' && str_starts_with($path, $p . '/'))) ? ' active' : '';
$isStudent = $u && $u->isStudent();
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<?= \Flatmates\Http\View::partial('partials/head', get_defined_vars()) ?>
<?php if (!empty($jsonLd)): ?><script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script><?php endif; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?><?= $isStudent ? ' has-bottom-nav' : '' ?>">
<a class="visually-hidden-focusable btn btn-light position-absolute m-2" href="#main">Skip to content</a>
<nav class="navbar navbar-expand-lg app-navbar sticky-top border-bottom">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="<?= e(url('/')) ?>">
      <span class="brand-mark"><?= e(setting('brand.logo_text', 'SF')) ?></span><span class="d-none d-sm-inline"><?= e(brand()) ?></span>
    </a>
    <div class="d-flex align-items-center gap-1 order-lg-last">
      <button class="btn btn-link nav-icon" type="button" data-theme-toggle aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button>
      <?php if ($u): ?>
        <a class="btn btn-link nav-icon position-relative" href="<?= e(url('/notifications')) ?>" aria-label="Notifications"><i class="bi bi-bell"></i><span class="badge rounded-pill bg-danger badge-dot" data-badge="notifications"<?= empty($unreadNotifications) ? ' hidden' : '' ?>><?= (int)($unreadNotifications ?? 0) ?></span></a>
        <div class="dropdown">
          <button class="btn btn-link nav-icon dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu"><i class="bi bi-person-circle"></i></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-body-secondary"><?= e($u->name) ?></span></li>
            <?php if ($isStudent): ?>
              <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person me-2"></i>My profile</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/verify')) ?>"><i class="bi bi-patch-check me-2"></i>Verification</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/my/listings')) ?>"><i class="bi bi-house-door me-2"></i>My listings</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/enquiries')) ?>"><i class="bi bi-envelope me-2"></i>Enquiries</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/saved')) ?>"><i class="bi bi-bookmark me-2"></i>Saved</a></li>
            <?php endif; ?>
            <?php if ($u->isStaff()): ?><li><a class="dropdown-item" href="<?= e(url('/admin')) ?>"><i class="bi bi-shield-lock me-2"></i>Admin</a></li><?php endif; ?>
            <li><a class="dropdown-item" href="<?= e(url('/account')) ?>"><i class="bi bi-gear me-2"></i>Account & privacy</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button></form></li>
          </ul>
        </div>
      <?php else: ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/login')) ?>">Sign in</a>
        <a class="btn btn-sm btn-primary d-none d-sm-inline-block" href="<?= e(url('/register')) ?>">Join free</a>
      <?php endif; ?>
    </div>
    <div class="d-none d-lg-flex ms-4 me-auto">
      <ul class="navbar-nav gap-1">
        <?php if ($isStudent): ?>
          <li class="nav-item"><a class="nav-link<?= $active('/discover') ?>" href="<?= e(url('/discover')) ?>">Discover</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/matches') ?>" href="<?= e(url('/matches')) ?>">Matches</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/chat') ?>" href="<?= e(url('/chat')) ?>">Messages <span class="badge rounded-pill bg-danger" data-badge="messages"<?= empty($unreadMessages) ? ' hidden' : '' ?>><?= (int)($unreadMessages ?? 0) ?></span></a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link<?= $active('/rooms') ?>" href="<?= e(url('/rooms')) ?>">Rooms</a></li>
        <?php if (!$u): ?><li class="nav-item"><a class="nav-link<?= $active('/cities') ?>" href="<?= e(url('/cities')) ?>">Cities</a></li><li class="nav-item"><a class="nav-link<?= $active('/safety') ?>" href="<?= e(url('/safety')) ?>">Safety</a></li><?php endif; ?>
        <?php if ($u && $u->isStaff()): ?><li class="nav-item"><a class="nav-link<?= $active('/admin') ?>" href="<?= e(url('/admin')) ?>">Admin</a></li><?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<?php if (!empty($adminNav)): ?><?= \Flatmates\Http\View::partial('admin/_nav', get_defined_vars()) ?><?php endif; ?>
<main id="main" class="<?= e($mainClass ?? 'container py-3 py-md-4') ?>">
  <?= \Flatmates\Http\View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
  <?php if ($u && $u->status === 'restricted'): ?><div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Your account is restricted: you can browse, but cannot message, swipe or post. <a href="<?= e(url('/account')) ?>">Details</a></div><?php endif; ?>
  <?= $content ?>
</main>
<footer class="site-footer border-top mt-5 py-4">
  <div class="container small text-body-secondary d-flex flex-column flex-md-row justify-content-between gap-2">
    <div>© <?= gmdate('Y') ?> <?= e(brand()) ?> · Operated by <a href="<?= e(setting('brand.company_url', 'https://noidarankers.in')) ?>" rel="noopener"><?= e(setting('brand.company', 'NoidaRankers')) ?></a></div>
    <nav class="d-flex flex-wrap gap-3"><a href="<?= e(url('/about')) ?>">About</a><a href="<?= e(url('/cities')) ?>">Cities</a><a href="<?= e(url('/safety')) ?>">Safety</a><a href="<?= e(url('/privacy')) ?>">Privacy</a><a href="<?= e(url('/terms')) ?>">Terms</a><a href="mailto:<?= e(setting('brand.support_email')) ?>">Contact</a></nav>
  </div>
</footer>
<?php if ($isStudent): ?>
<nav class="bottom-nav d-lg-none" aria-label="Main">
  <a class="<?= trim($active('/discover')) ?>" href="<?= e(url('/discover')) ?>"><i class="bi bi-stars"></i><span>Discover</span></a>
  <a class="<?= trim($active('/rooms')) ?>" href="<?= e(url('/rooms')) ?>"><i class="bi bi-house"></i><span>Rooms</span></a>
  <a class="<?= trim($active('/matches')) ?>" href="<?= e(url('/matches')) ?>"><i class="bi bi-heart"></i><span>Matches</span></a>
  <a class="<?= trim($active('/chat')) ?>" href="<?= e(url('/chat')) ?>"><i class="bi bi-chat-dots"></i><span>Chat</span><b class="badge rounded-pill bg-danger" data-badge="messages"<?= empty($unreadMessages) ? ' hidden' : '' ?>><?= (int)($unreadMessages ?? 0) ?></b></a>
  <a class="<?= trim($active('/profile')) ?>" href="<?= e(url('/profile')) ?>"><i class="bi bi-person"></i><span>Me</span></a>
</nav>
<?php endif; ?>
<?= \Flatmates\Http\View::partial('partials/report_modal', []) ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts ?? [] as $s): ?><script src="<?= e(asset('js/' . $s)) ?>"></script><?php endforeach; ?>
</body>
</html>
