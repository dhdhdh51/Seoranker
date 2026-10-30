<?php use Flatmates\Services\TrustService; ?>
<div class="page-head"><div><span class="eyebrow"><i class="bi bi-shield-check" aria-hidden="true"></i> Safety centre</span><h1 class="mt-2">Stay safe while you find your place</h1><p>Practical guidance, what our badges mean, and how to get help.</p></div></div>
<div class="notice notice-danger mb-4"><i class="bi bi-telephone" aria-hidden="true"></i><div><strong>In an emergency call 112.</strong> Women's helpline <strong>1091</strong>. Cyber-crime and online fraud: <strong>1930</strong> or <a href="https://cybercrime.gov.in" rel="noopener">cybercrime.gov.in</a>.</div></div>
<div class="row g-4">
  <nav class="col-lg-3 d-none d-lg-block" aria-label="On this page"><div class="toc sticky-cta surface p-2"><a href="#badges">What badges mean</a><a href="#scams">Avoid rental scams</a><a href="#meeting">Meeting safely</a><a href="#rules">Community rules</a><a href="#report">Report &amp; block</a><a href="#privacy">Your privacy</a></div></nav>
  <div class="col-lg-9 content-page">
    <section id="badges">
      <h2 class="mt-0">What our badges mean — and what they don't</h2>
      <p class="text-body-secondary">We only show a badge when the check described below was actually completed for that account or listing. <strong>No badge guarantees a person's identity, safety, honesty, or that someone owns or is allowed to rent out a property.</strong></p>
      <div class="row g-3">
        <?php foreach (TrustService::BADGES as $k => $d): ?>
          <div class="col-md-6"><div class="surface p-3 h-100">
            <?= \Flatmates\Http\View::partial('partials/badges', ['keys' => [$k]]) ?>
            <p class="small mt-2 mb-1"><strong>Means:</strong> <?= e($d['means']) ?></p>
            <p class="small text-body-secondary mb-0"><strong>Does not mean:</strong> <?= e($d['not']) ?></p>
          </div></div>
        <?php endforeach; ?>
      </div>
    </section>
    <section id="scams">
      <h2>Avoid rental scams</h2>
      <?= \Flatmates\Http\View::partial('partials/safety_notice', ['class' => 'mb-3']) ?>
      <ul>
        <li><strong>Never pay a token, deposit or "visit fee" before seeing the place in person</strong> and meeting the person offering it.</li>
        <li>Ask who is authorised to rent the place out — the owner, a tenant allowed to sub-let, or an agent — and ask to see something that shows it.</li>
        <li>Be wary of rent far below the area average, pressure to "book today", stories about being abroad, or requests to pay by gift card, crypto or to a personal account you can't verify.</li>
        <li>Read the full terms: rent, deposit and refund conditions, notice period, electricity/water/maintenance charges and house rules. Get them <strong>in writing</strong> and keep receipts for every payment.</li>
        <li>Our team will never ask for your password, an OTP or a payment.</li>
      </ul>
    </section>
    <section id="meeting">
      <h2>Meeting safely</h2>
      <ul><li>Chat in the app first and do a video call before meeting.</li><li>Meet in a public place first and tell a friend where you're going.</li><li>Visit the property in daylight, ideally with a friend.</li><li>Trust your instincts — you can unmatch or block at any time.</li></ul>
    </section>
    <section id="rules">
      <h2>Community rules</h2>
      <ul><li>No harassment, hate speech or threats.</li><li>No discrimination on religion, caste, region, race, sexual orientation or disability. Gender-specific housing is allowed only as a stated preference.</li><li>No fake profiles, impersonation, or commercial brokers posing as students.</li><li>Don't post contact details, other people's photos or personal documents publicly.</li></ul>
    </section>
    <section id="report">
      <h2>Report &amp; block</h2>
      <p>Every profile, listing and message has <strong>Report</strong> and <strong>Block</strong>. Reports are confidential — the other person isn't told who reported them. Moderators review every report, and serious ones (harassment, scams, safety) are prioritised. Listings with several independent reports are hidden automatically until reviewed.</p>
      <p>Blocking hides you from each other everywhere and ends any match. You can manage blocked people in <a href="<?= e(url('/account')) ?>">Account &amp; privacy</a>.</p>
    </section>
    <section id="privacy">
      <h2>Your privacy</h2>
      <p>Other students never see your email, phone, full name, date of birth, verification documents or a listing's exact address (shared only with students whose enquiry you accept). Student documents are kept outside the public website, viewable only by authorised moderators, and deleted after review. Read the full <a href="<?= e(url('/privacy')) ?>">privacy policy</a>.</p>
    </section>
  </div>
</div>
