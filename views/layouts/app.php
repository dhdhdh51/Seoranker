<?php
/** @var string $content */
use Flatmates\Http\View;

$u = $currentUser ?? null;
$path = $currentPath ?? '/';
$active = static fn(string $p): string => ($path === $p || ($p !== '/' && str_starts_with($path, $p . '/'))) ? ' active' : '';
$cur = static fn(string $p): string => ($path === $p || ($p !== '/' && str_starts_with($path, $p . '/'))) ? ' aria-current="page"' : '';
$isStudent = $u && $u->isStudent();
$inApp = (bool)View::shared('inApp', false);
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<?= View::partial('partials/head', get_defined_vars()) ?>
<?php if (!empty($jsonLd)): ?><script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script><?php endif; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?><?= $isStudent ? ' has-bottom-nav' : '' ?><?= $inApp ? ' in-app' : '' ?>">
<a class="visually-hidden-focusable btn btn-light position-absolute m-2" style="z-index:2000" href="#main">Skip to content</a>
<nav class="navbar navbar-expand-lg app-navbar sticky-top" aria-label="Primary">
  <div class="container">
    <a class="brand" href="<?= e(url($u ? ($u->isStaff() ? '/admin' : '/dashboard') : '/')) ?>" aria-label="<?= e(brand()) ?> home">
      <?= View::partial('partials/brand_mark') ?><span class="brand-name d-none d-sm-inline"><?= e(brand()) ?></span>
    </a>
    <div class="d-none d-lg-flex ms-4 me-auto">
      <ul class="navbar-nav gap-1">
        <?php if ($isStudent): ?>
          <li class="nav-item"><a class="nav-link<?= $active('/dashboard') ?>"<?= $cur('/dashboard') ?> href="<?= e(url('/dashboard')) ?>">Home</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/discover') ?>"<?= $cur('/discover') ?> href="<?= e(url('/discover')) ?>">Discover</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/rooms') ?>"<?= $cur('/rooms') ?> href="<?= e(url('/rooms')) ?>">Rooms</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/matches') ?>"<?= $cur('/matches') ?> href="<?= e(url('/matches')) ?>">Matches</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/chat') ?>"<?= $cur('/chat') ?> href="<?= e(url('/chat')) ?>">Messages <span class="badge rounded-pill bg-danger" data-badge="messages"<?= empty($unreadMessages) ? ' hidden' : '' ?>><?= (int)($unreadMessages ?? 0) ?></span></a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link<?= $active('/rooms') ?>"<?= $cur('/rooms') ?> href="<?= e(url('/rooms')) ?>">Explore rooms</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/cities') ?>"<?= $cur('/cities') ?> href="<?= e(url('/cities')) ?>">Cities</a></li>
          <li class="nav-item"><a class="nav-link<?= $active('/safety') ?>"<?= $cur('/safety') ?> href="<?= e(url('/safety')) ?>">Safety</a></li>
          <?php if (!$inApp): ?><li class="nav-item"><a class="nav-link<?= $active('/app') ?>"<?= $cur('/app') ?> href="<?= e(url('/app')) ?>">Android app</a></li><?php endif; ?>
          <?php if ($u && $u->isStaff()): ?><li class="nav-item"><a class="nav-link<?= $active('/admin') ?>" href="<?= e(url('/admin')) ?>">Admin</a></li><?php endif; ?>
        <?php endif; ?>
      </ul>
    </div>
    <div class="d-flex align-items-center gap-1 ms-auto ms-lg-0">
      <button class="nav-icon" type="button" data-theme-toggle aria-label="Switch dark mode"><i class="bi bi-moon-stars" aria-hidden="true"></i></button>
      <?php if ($u): ?>
        <a class="nav-icon" href="<?= e(url('/notifications')) ?>" aria-label="Notifications<?= !empty($unreadNotifications) ? ' (' . (int)$unreadNotifications . ' unread)' : '' ?>"><i class="bi bi-bell" aria-hidden="true"></i><span class="badge rounded-pill bg-danger badge-dot" data-badge="notifications"<?= empty($unreadNotifications) ? ' hidden' : '' ?>><?= (int)($unreadNotifications ?? 0) ?></span></a>
        <div class="dropdown">
          <button class="nav-icon dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu"><span class="avatar avatar-sm avatar-initials" aria-hidden="true"><?= e(initials($u->name)) ?></span></button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="px-3 py-2"><div class="fw-600"><?= e($u->name) ?></div><div class="small text-body-secondary"><?= e(label('role', $u->role)) ?></div></li>
            <li><hr class="dropdown-divider"></li>
            <?php if ($isStudent): ?>
              <li><a class="dropdown-item" href="<?= e(url('/profile')) ?>"><i class="bi bi-person me-2" aria-hidden="true"></i>My profile</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/verify')) ?>"><i class="bi bi-patch-check me-2" aria-hidden="true"></i>Verification centre</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/my/listings')) ?>"><i class="bi bi-house-door me-2" aria-hidden="true"></i>My listings</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/enquiries')) ?>"><i class="bi bi-envelope me-2" aria-hidden="true"></i>Enquiries</a></li>
              <li><a class="dropdown-item" href="<?= e(url('/saved')) ?>"><i class="bi bi-bookmark me-2" aria-hidden="true"></i>Saved</a></li>
            <?php endif; ?>
            <?php if ($u->isStaff()): ?><li><a class="dropdown-item" href="<?= e(url('/admin')) ?>"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Admin</a></li><?php endif; ?>
            <li><a class="dropdown-item" href="<?= e(url('/account')) ?>"><i class="bi bi-gear me-2" aria-hidden="true"></i>Account &amp; privacy</a></li>
            <li><a class="dropdown-item" href="<?= e(url('/safety')) ?>"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Safety centre</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button></form></li>
          </ul>
        </div>
      <?php else: ?>
        <a class="btn btn-sm btn-light ms-1" href="<?= e(url('/login')) ?>">Sign in</a>
        <a class="btn btn-sm btn-primary ms-1 d-none d-sm-inline-flex" href="<?= e(url('/register')) ?>">Join free</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<?php if (!empty($adminNav)): ?><?= View::partial('admin/_nav', get_defined_vars()) ?><?php endif; ?>
<main id="main" class="<?= e($mainClass ?? 'container py-4') ?>">
  <?= View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
  <?php if ($u && $u->status === 'restricted'): ?><div class="notice notice-danger mb-3"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>Your account is restricted: you can browse, but cannot message, swipe or post. <a href="<?= e(url('/account')) ?>">See details</a></div></div><?php endif; ?>
  <?= $content ?>
</main>
<footer class="site-footer">
  <div class="container py-5">
    <div class="row g-4">
      <div class="col-lg-4">
        <a class="brand mb-3" href="<?= e(url('/')) ?>"><?= View::partial('partials/brand_mark') ?><span><?= e(brand()) ?></span></a>
        <p class="small mt-3 mb-2" style="max-width:22rem">Find compatible student flatmates and rooms near your college. Free for students, no brokerage.</p>
        <p class="small mb-0">Operated by <a href="<?= e(setting('brand.company_url', 'https://noidarankers.in')) ?>" rel="noopener"><?= e(setting('brand.company', 'NoidaRankers')) ?></a></p>
      </div>
      <div class="col-6 col-md-3 col-lg-2"><h2>Explore</h2><ul class="list-unstyled footer-links small"><li><a href="<?= e(url('/rooms')) ?>">Rooms &amp; flats</a></li><li><a href="<?= e(url('/cities')) ?>">Cities &amp; colleges</a></li><li><a href="<?= e(url('/register')) ?>">Find a flatmate</a></li><li><a href="<?= e(url('/about')) ?>">About us</a></li></ul></div>
      <div class="col-6 col-md-3 col-lg-2"><h2>Trust &amp; safety</h2><ul class="list-unstyled footer-links small"><li><a href="<?= e(url('/safety')) ?>">Safety centre</a></li><li><a href="<?= e(url('/safety')) ?>#badges">What badges mean</a></li><li><a href="<?= e(url('/safety')) ?>#scams">Avoid rental scams</a></li><li><a href="<?= e(url('/safety')) ?>#report">Report a problem</a></li></ul></div>
      <div class="col-6 col-md-3 col-lg-2"><h2>Legal</h2><ul class="list-unstyled footer-links small"><li><a href="<?= e(url('/terms')) ?>">Terms of use</a></li><li><a href="<?= e(url('/privacy')) ?>">Privacy policy</a></li><li><a href="mailto:<?= e(setting('brand.support_email')) ?>">Contact support</a></li></ul></div>
      <div class="col-6 col-md-3 col-lg-2 hide-in-app"><h2>Get the app</h2><ul class="list-unstyled footer-links small"><li><a href="<?= e(url('/app')) ?>"><i class="bi bi-android2 me-1" aria-hidden="true"></i>Download Android app</a></li><li><a href="<?= e(url('/app')) ?>#install">How to install</a></li></ul></div>
    </div>
  </div>
  <div class="footer-bottom"><div class="container py-3 small d-flex flex-column flex-md-row justify-content-between gap-2">
    <span>© <?= gmdate('Y') ?> <?= e(brand()) ?>. All rights reserved.</span>
    <span>Emergency: <strong>112</strong> · Women's helpline <strong>1091</strong> · Cyber-crime <strong>1930</strong></span>
  </div></div>
</footer>
<?php if ($isStudent): ?>
<nav class="bottom-nav d-lg-none" aria-label="Main">
  <a class="<?= trim($active('/dashboard')) ?>"<?= $cur('/dashboard') ?> href="<?= e(url('/dashboard')) ?>"><i class="bi bi-house" aria-hidden="true"></i><span>Home</span></a>
  <a class="<?= trim($active('/discover')) ?>"<?= $cur('/discover') ?> href="<?= e(url('/discover')) ?>"><i class="bi bi-stars" aria-hidden="true"></i><span>Discover</span></a>
  <a class="<?= trim($active('/rooms')) ?>"<?= $cur('/rooms') ?> href="<?= e(url('/rooms')) ?>"><i class="bi bi-door-open" aria-hidden="true"></i><span>Rooms</span></a>
  <a class="<?= trim($active('/matches')) ?>"<?= $cur('/matches') ?> href="<?= e(url('/matches')) ?>"><i class="bi bi-heart" aria-hidden="true"></i><span>Matches</span></a>
  <a class="<?= trim($active('/chat')) ?>"<?= $cur('/chat') ?> href="<?= e(url('/chat')) ?>"><i class="bi bi-chat-dots" aria-hidden="true"></i><span>Chat</span><b class="badge rounded-pill bg-danger" data-badge="messages"<?= empty($unreadMessages) ? ' hidden' : '' ?>><?= (int)($unreadMessages ?? 0) ?></b></a>
</nav>
<?php endif; ?>
<?= View::partial('partials/report_modal', []) ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>" defer></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $s): ?><script src="<?= e(asset('js/' . $s)) ?>" defer></script><?php endforeach; ?>
</body>
</html>
