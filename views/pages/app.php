<?php
/** @var \Flatmates\Services\AppRelease $rel */
use Flatmates\Http\View;

$inApp = (bool)View::shared('inApp', false);
?>
<div class="row g-4 g-lg-5 align-items-start">
  <div class="col-lg-7">
    <span class="eyebrow"><i class="bi bi-android2" aria-hidden="true"></i> Official Android app</span>
    <h1 class="mt-2" style="font-size:var(--fm-fs-h2)">Download <?= e(brand()) ?> for Android</h1>
    <p class="section-lead">The same account and the same privacy protections as the website, in an app that handles photo uploads, the back button and poor connections gracefully.</p>

    <?php if ($inApp): ?>
      <div class="notice notice-info my-4"><i class="bi bi-check-circle" aria-hidden="true"></i><div>You're already using the Android app<?= $rel->version() ? ' — the latest published version is v' . e($rel->version()) : '' ?>.</div></div>
    <?php elseif ($rel->available()): ?>
      <div class="surface p-4 my-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
          <a class="btn btn-primary btn-lg" href="<?= e($rel->downloadUrl()) ?>" rel="noopener" data-download-apk><i class="bi bi-download" aria-hidden="true"></i> Download Android App (APK)</a>
          <div class="small text-body-secondary">
            <?= $rel->version() ? '<strong class="text-body">Version ' . e($rel->version()) . '</strong>' : '' ?>
            <?= $rel->releaseDate() ? '<br>Released ' . e(fmt_date($rel->releaseDate())) : '' ?>
            <br>Android 7.0 or newer · .apk file
          </div>
        </div>
        <?php if ($rel->sha256()): ?>
          <div class="mt-3"><div class="small fw-600 mb-1">SHA-256 checksum</div><div class="checksum" id="apkSha"><?= e($rel->sha256()) ?></div>
          <div class="form-text">Optional: compare this with the checksum of the downloaded file to confirm it wasn't altered.</div></div>
        <?php endif; ?>
        <?php if ($rel->releasesUrl()): ?><p class="small mt-3 mb-0">Trouble downloading? Get it from the <a href="<?= e($rel->releasesUrl()) ?>" rel="noopener">official releases page</a>.</p><?php endif; ?>
      </div>
    <?php else: ?>
      <div class="notice notice-info my-4"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong class="d-block">No public release yet</strong>The Android app hasn't been published yet. This page will show the official download, version and checksum as soon as it is.<?= $rel->releasesUrl() ? ' You can also watch the <a href="' . e($rel->releasesUrl()) . '" rel="noopener">official releases page</a>.' : '' ?></div></div>
    <?php endif; ?>

    <h2 class="h4 mt-5" id="install">How to install</h2>
    <ol class="mt-3 d-grid gap-2">
      <li>Open this page on your Android phone and tap <strong>Download Android App</strong>.</li>
      <li>When the download finishes, open the <code>.apk</code> file from your notifications or the Downloads folder.</li>
      <li>Android may ask you to allow your browser to install apps ("Install unknown apps"). Allow it for this one install if you trust the source — you can switch it off again afterwards in Settings.</li>
      <li>Tap <strong>Install</strong>, then open the app and sign in with your usual account.</li>
    </ol>
    <div class="notice notice-safety mt-4"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><div><strong class="d-block">Only install official releases</strong>Download the app only from this page or our official releases page. We will never send you an APK over WhatsApp, Telegram or email, and you never need to turn off Google Play Protect to install it.</div></div>

    <h2 class="h4 mt-5">Troubleshooting</h2>
    <div class="faq mt-3">
      <details><summary>"App not installed" or "package conflicts"</summary><div>Uninstall any older copy that came from somewhere else, then install the official file again. Updates install over the top of the official app without losing your account.</div></details>
      <details><summary>The download doesn't start</summary><div>Try a different browser (Chrome works well), check your free storage, or use the releases page link above.</div></details>
      <details><summary>The app says "You're offline"</summary><div>Check your mobile data or Wi-Fi and tap <em>Try again</em>. The app only connects to <?= e(parse_url(absolute_url('/'), PHP_URL_HOST) ?: '') ?> over a secure connection.</div></details>
      <details><summary>I use an iPhone</summary><div>There is no iOS app yet — the website works fully in Safari and can be added to your home screen.</div></details>
    </div>
  </div>
  <aside class="col-lg-5">
    <div class="surface p-4 text-center sticky-cta">
      <?php if ($rel->qrTarget() && !$inApp): ?>
        <div class="qr-box mx-auto"><img src="<?= e(url('/app/qr.svg')) ?>" alt="QR code linking to the official Android download" width="148" height="148"></div>
        <p class="small text-body-secondary mt-3 mb-1">On a computer? Scan this with your Android phone's camera.</p>
        <p class="small mb-0">Can't scan? Open <a class="text-break" href="<?= e($rel->qrTarget()) ?>" rel="noopener"><?= e(str_limit($rel->qrTarget(), 70)) ?></a> on your phone.</p>
      <?php else: ?>
        <div class="empty-ico mx-auto" style="width:72px;height:72px;display:grid;place-items:center;border-radius:50%;background:var(--fm-blue-50);color:var(--brand);font-size:2rem" aria-hidden="true"><i class="bi bi-phone"></i></div>
        <p class="small text-body-secondary mt-3 mb-0">The full experience also works in any mobile browser — no install needed.</p>
      <?php endif; ?>
      <div class="divider"></div>
      <ul class="list-unstyled text-start small d-grid gap-2 mb-0">
        <li><i class="bi bi-lock text-primary me-2" aria-hidden="true"></i>Connects only to our secure (HTTPS) site</li>
        <li><i class="bi bi-person-check text-primary me-2" aria-hidden="true"></i>Same account, matches and chats as the website</li>
        <li><i class="bi bi-images text-primary me-2" aria-hidden="true"></i>Upload profile and room photos from your gallery</li>
        <li><i class="bi bi-box-arrow-up-right text-primary me-2" aria-hidden="true"></i>Outside links open in your browser, not inside the app</li>
      </ul>
    </div>
  </aside>
</div>
