<?php
\Flatmates\Http\View::share('jsonLd', [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'WebSite', 'name' => brand(), 'url' => absolute_url('/'), 'potentialAction' => ['@type' => 'SearchAction', 'target' => absolute_url('/rooms') . '?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
        ['@type' => 'Organization', 'name' => (string)setting('brand.company', 'NoidaRankers'), 'url' => (string)setting('brand.company_url', 'https://noidarankers.in'), 'email' => (string)setting('brand.support_email')],
        ['@type' => 'FAQPage', 'mainEntity' => [
            ['@type' => 'Question', 'name' => 'Is ' . brand() . ' free?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes. Creating a profile, matching, chatting and listing a room are free for students. There is no brokerage.']],
            ['@type' => 'Question', 'name' => 'How do you verify students?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Students can confirm an official college email address with a one-time code, or upload a college ID that a moderator reviews and then deletes.']],
            ['@type' => 'Question', 'name' => 'Who can see my phone number?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Nobody. We never show phone numbers or email addresses. You chat inside the app only after a mutual match or an accepted enquiry.']],
        ]],
    ],
]);
?>
<section class="hero rounded-4 p-4 p-md-5 mb-5">
  <div class="row align-items-center g-4">
    <div class="col-lg-7">
      <span class="badge rounded-pill hero-pill mb-3"><i class="bi bi-patch-check-fill"></i> Students only · Verified · Free</span>
      <h1 class="display-5 fw-bold">Find a flatmate who actually fits your life.</h1>
      <p class="lead mt-3"><?= e(setting('brand.tagline')) ?> Match on budget, college, sleep schedule, cleanliness and food habits — then chat privately before you meet.</p>
      <div class="d-flex flex-wrap gap-2 mt-4">
        <a class="btn btn-light btn-lg fw-semibold" href="<?= e(url('/register')) ?>">Start matching — it's free</a>
        <a class="btn btn-outline-light btn-lg" href="<?= e(url('/rooms')) ?>">Browse rooms</a>
      </div>
    </div>
    <div class="col-lg-5 d-none d-lg-block">
      <div class="hero-card shadow-lg">
        <div class="d-flex align-items-center gap-3"><span class="avatar avatar-lg avatar-initials">AK</span><div><strong>Aarav, 20</strong> <i class="bi bi-patch-check-fill text-primary"></i><div class="small text-body-secondary">B.Tech · Sector 62</div></div><span class="ms-auto score-pill">92%</span></div>
        <div class="d-flex flex-wrap gap-2 mt-3 small"><span class="chip chip-ok">Similar budget</span><span class="chip chip-ok">Both non-smokers</span><span class="chip chip-ok">Night owls</span></div>
        <div class="small text-body-secondary mt-2">Illustration — not a real profile</div>
      </div>
    </div>
  </div>
</section>

<section class="mb-5">
  <h2 class="h4 text-center mb-4">How it works</h2>
  <div class="row g-3 text-center">
    <div class="col-md-3 col-6"><div class="step-ico"><i class="bi bi-person-vcard"></i></div><h3 class="h6 mt-2">1. Build your profile</h3><p class="small text-body-secondary">Budget, college, move-in date and lifestyle in 4 quick steps.</p></div>
    <div class="col-md-3 col-6"><div class="step-ico"><i class="bi bi-patch-check"></i></div><h3 class="h6 mt-2">2. Get verified</h3><p class="small text-body-secondary">Confirm your college email or ID for a trusted badge.</p></div>
    <div class="col-md-3 col-6"><div class="step-ico"><i class="bi bi-hand-index-thumb"></i></div><h3 class="h6 mt-2">3. Swipe & match</h3><p class="small text-body-secondary">See a compatibility score with reasons. Match when you both like.</p></div>
    <div class="col-md-3 col-6"><div class="step-ico"><i class="bi bi-chat-heart"></i></div><h3 class="h6 mt-2">4. Chat & move in</h3><p class="small text-body-secondary">Private chat — no phone numbers shared until you're ready.</p></div>
  </div>
</section>

<?php if ($latest): ?>
<section class="mb-5">
  <div class="d-flex justify-content-between align-items-end mb-3"><h2 class="h4 mb-0">Latest rooms & vacant beds</h2><a href="<?= e(url('/rooms')) ?>">See all</a></div>
  <div class="row g-3"><?php foreach ($latest as $l): ?><div class="col-sm-6 col-lg-4"><?= \Flatmates\Http\View::partial('partials/listing_card', ['l' => $l]) ?></div><?php endforeach; ?></div>
</section>
<?php endif; ?>

<section class="mb-5">
  <h2 class="h4 mb-3">Popular student cities</h2>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($cities as $c): ?><a class="btn btn-outline-secondary rounded-pill" href="<?= e(url('/flatmates/' . $c['slug'])) ?>">Flatmates in <?= e($c['name']) ?><?= $c['listings'] ? ' <span class="badge text-bg-light">' . (int)$c['listings'] . '</span>' : '' ?></a><?php endforeach; ?>
  </div>
</section>

<section class="row g-4 mb-5">
  <div class="col-md-6"><div class="card h-100 border-0 shadow-sm"><div class="card-body">
    <h2 class="h5"><i class="bi bi-shield-lock text-primary me-1"></i>Built for safety & privacy</h2>
    <ul class="small mb-0">
      <li>Students only, 18+, email-verified accounts</li>
      <li>Phone, email, exact address and documents are never public</li>
      <li>Report & block on every profile, listing and message — reviewed by real moderators</li>
      <li>Sensitive details like gender and age are hidden unless you choose to show them</li>
    </ul>
  </div></div></div>
  <div class="col-md-6"><div class="card h-100 border-0 shadow-sm"><div class="card-body">
    <h2 class="h5"><i class="bi bi-question-circle text-primary me-1"></i>FAQ</h2>
    <p class="small mb-1"><strong>Is it free?</strong> Yes — no brokerage, no fees for students.</p>
    <p class="small mb-1"><strong>How is compatibility calculated?</strong> We compare budget, move-in timing, college, area, tidiness, sleep, smoking, drinking, food and study habits, and show you why.</p>
    <p class="small mb-0"><strong>Can I list my vacant bed?</strong> Yes — post a flat, shared room or vacant bed in a few minutes.</p>
  </div></div></div>
</section>
