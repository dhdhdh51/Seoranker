<?php
/** @var \Flatmates\Services\AppRelease $rel */
$hid = $headingId ?? 'appBandTitle';
?>
<div class="app-band p-4 p-lg-5">
  <div class="row g-4 align-items-center">
    <div class="col-lg-7">
      <span class="eyebrow" style="color:#8fb6ff"><i class="bi bi-android2" aria-hidden="true"></i> Android app</span>
      <h2 class="section-title mt-2" id="<?= e($hid) ?>">Take your flat hunt with you</h2>
      <p class="mb-3">The official Android app uses your same account: swipe, chat and upload photos with a native feel and offline-friendly error screens.</p>
      <?php if ($rel->available()): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <a class="btn btn-primary btn-lg" href="<?= e(url('/app')) ?>"><i class="bi bi-download" aria-hidden="true"></i> Download Android App</a>
          <span class="small" style="color:rgba(255,255,255,.75)">APK<?= $rel->version() ? ' · v' . e($rel->version()) : '' ?><?= $rel->releaseDate() ? ' · ' . e(fmt_date($rel->releaseDate())) : '' ?></span>
        </div>
      <?php else: ?>
        <p class="small mb-2" style="color:rgba(255,255,255,.75)"><i class="bi bi-hourglass-split" aria-hidden="true"></i> The first public release hasn't been published yet.</p>
        <a class="btn btn-outline-light" href="<?= e(url('/app')) ?>">Android app details</a>
      <?php endif; ?>
    </div>
    <?php if ($rel->qrTarget()): ?>
    <div class="col-lg-5 d-none d-md-flex justify-content-lg-end align-items-center gap-3">
      <div class="small text-end" style="color:rgba(255,255,255,.75);max-width:11rem">Scan with your Android phone's camera to open the official download</div>
      <div class="qr-box"><img src="<?= e(url('/app/qr.svg')) ?>" alt="QR code linking to the official Android download" width="148" height="148" loading="lazy"></div>
    </div>
    <?php endif; ?>
  </div>
</div>
