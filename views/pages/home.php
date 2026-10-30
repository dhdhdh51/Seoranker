<?php
use Flatmates\Http\View;
use Flatmates\Services\TrustService;

$faqs = [
    ['Is it free for students?', 'Yes. Creating a profile, matching, chatting and listing a room are free. There is no brokerage.'],
    ['How is the compatibility score worked out?', 'We compare the answers you both gave — budget overlap, move-in date, college and area, tidiness, sleep schedule, smoking and drinking tolerance, food and kitchen preferences, study and social habits — and show the reasons behind every score. Questions either of you skipped are left out instead of counting against you.'],
    ['What do the verification badges mean?', 'Each badge is backed by a specific check (email code, college-email code, or a moderator reviewing a student document). Badges reduce risk but do not guarantee anyone\'s identity, safety or honesty. See the Safety centre for exactly what each one covers.'],
    ['Who can see my phone number or email?', 'Nobody. We never show phone numbers, email addresses, documents or exact addresses on profiles. You chat inside the app after a mutual match or after a listing owner accepts your enquiry.'],
    ['Can I list my vacant bed or room?', 'Yes. Post a whole flat, a shared room or a single vacant bed. Listings are checked by a moderator before they go live, and the exact address is shared only with students whose enquiry you accept.'],
    ['Is there a mobile app?', 'There is an official Android app that uses the same secure account as the website. See the Android app page for the current version and installation steps.'],
];
View::share('jsonLd', [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'WebSite', 'name' => brand(), 'url' => absolute_url('/'), 'potentialAction' => ['@type' => 'SearchAction', 'target' => absolute_url('/rooms') . '?q={search_term_string}', 'query-input' => 'required name=search_term_string']],
        ['@type' => 'Organization', 'name' => (string)setting('brand.company', 'NoidaRankers'), 'url' => (string)setting('brand.company_url', 'https://noidarankers.in'), 'email' => (string)setting('brand.support_email')],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(static fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs)],
    ],
]);
$pic = static fn(string $name, string $alt, string $class = '', bool $eager = false, string $sizes = '(min-width: 992px) 50vw, 100vw') =>
    '<picture><source type="image/webp" srcset="' . e(asset('img/photos/' . $name . '-480.webp')) . ' 480w, ' . e(asset('img/photos/' . $name . '-960.webp')) . ' 960w" sizes="' . e($sizes) . '">'
    . '<img src="' . e(asset('img/photos/' . $name . '-960.jpg')) . '" srcset="' . e(asset('img/photos/' . $name . '-480.jpg')) . ' 480w, ' . e(asset('img/photos/' . $name . '-960.jpg')) . ' 960w" sizes="' . e($sizes) . '" alt="' . e($alt) . '" width="960" height="640" class="' . e($class) . '"' . ($eager ? ' fetchpriority="high"' : ' loading="lazy" decoding="async"') . '></picture>';
?>
<section class="hero reveal" aria-labelledby="heroTitle">
  <div class="hero-media" aria-hidden="true"><?= $pic('friends', '', '', true, '100vw') ?></div>
  <div class="hero-inner">
    <span class="hero-pill"><i class="bi bi-shield-check" aria-hidden="true"></i> For college students · Free · No brokerage</span>
    <h1 id="heroTitle">Find Your People. <span>Find Your Place.</span></h1>
    <p class="lead">Discover compatible student flatmates, explore rooms and make your next move with confidence.</p>
    <div class="d-flex flex-wrap gap-2 mt-4">
      <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>"><i class="bi bi-people" aria-hidden="true"></i> Find My Flatmate</a>
      <a class="btn btn-outline-light btn-lg" href="<?= e(url('/rooms')) ?>"><i class="bi bi-door-open" aria-hidden="true"></i> Explore Rooms</a>
    </div>
    <form class="search-panel" method="get" action="<?= e(url('/rooms')) ?>" role="search" aria-label="Search rooms">
      <div><label for="hcity">City</label>
        <select class="form-select" id="hcity" name="city_id"><option value="">All cities</option><?php foreach ($cities as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="hq">College or locality</label>
        <input class="form-control" id="hq" name="q" placeholder="e.g. Amity, Sector 62" maxlength="80"></div>
      <div class="d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-search" aria-hidden="true"></i> Search</button></div>
    </form>
  </div>
</section>

<section class="section" aria-labelledby="howTitle">
  <div class="text-center mb-5"><span class="eyebrow">How it works</span><h2 class="section-title mt-2" id="howTitle">From "new city" to "new home" in three steps</h2><p class="section-lead mx-auto">No endless group chats or random calls. Tell us how you live, meet people who fit, and talk safely inside the app.</p></div>
  <div class="row g-3 g-lg-4">
    <div class="col-md-4"><div class="step-card"><div class="step-num">1</div><h3 class="h5">Build your profile</h3><p class="text-body-secondary mb-0">Budget, college, move-in date and lifestyle in four short steps. You choose what others can see — gender and age stay hidden unless you show them.</p></div></div>
    <div class="col-md-4"><div class="step-card"><div class="step-num">2</div><h3 class="h5">Swipe & match</h3><p class="text-body-secondary mb-0">See a compatibility score with plain-language reasons. Like or pass — when you both like each other, it's a match.</p></div></div>
    <div class="col-md-4"><div class="step-card"><div class="step-num">3</div><h3 class="h5">Chat, visit & decide</h3><p class="text-body-secondary mb-0">Message privately without sharing your number, visit together, and only commit once you have seen the place and the terms in writing.</p></div></div>
  </div>
</section>

<?php if ($latest): ?>
<section class="section pt-0" aria-labelledby="roomsTitle">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
    <div><span class="eyebrow">Rooms & flats</span><h2 class="section-title mt-2 mb-0" id="roomsTitle">Recently listed by students</h2></div>
    <a class="btn btn-light" href="<?= e(url('/rooms')) ?>">See all rooms <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
  </div>
  <div class="row g-3 g-lg-4"><?php foreach ($latest as $l): ?><div class="col-sm-6 col-lg-4"><?= View::partial('partials/listing_card', ['l' => $l]) ?></div><?php endforeach; ?></div>
</section>
<?php else: ?>
<section class="section pt-0" aria-labelledby="roomsTitle">
  <div class="feature-split">
    <div class="feature-img"><?= $pic('student-room', 'A tidy student room with a bed, desk and shelves') ?></div>
    <div><span class="eyebrow">Rooms & flats</span><h2 class="section-title mt-2" id="roomsTitle">Have a vacant bed? List it free.</h2>
      <p class="section-lead">Post a whole flat, a shared room or a single vacant bed. A moderator reviews each listing before it goes live, and your exact address is only shared with students whose enquiry you accept.</p>
      <a class="btn btn-primary" href="<?= e(url('/register')) ?>">Post a room</a> <a class="btn btn-light" href="<?= e(url('/rooms')) ?>">Browse rooms</a></div>
  </div>
</section>
<?php endif; ?>

<section class="section pt-0" aria-labelledby="trustTitle">
  <div class="feature-split">
    <div class="order-lg-2 feature-img"><?= $pic('studying', 'A student working on a laptop with a notebook') ?></div>
    <div>
      <span class="eyebrow"><i class="bi bi-patch-check" aria-hidden="true"></i> Verified students</span>
      <h2 class="section-title mt-2" id="trustTitle">Badges that say exactly what was checked</h2>
      <p class="section-lead">We only show a badge when a real check was completed — and we tell you what it does <em>not</em> prove.</p>
      <div class="surface p-3 mt-3">
        <?php foreach (['email_verified', 'college_email', 'student_id', 'listing_reviewed'] as $k): $d = TrustService::BADGES[$k]; ?>
          <div class="trust-row"><span class="trust-ico ok"><i class="bi bi-<?= e($d['icon']) ?>" aria-hidden="true"></i></span><div><strong><?= e($d['label']) ?></strong><div class="small text-body-secondary"><?= e($d['means']) ?></div></div></div>
        <?php endforeach; ?>
      </div>
      <a class="btn btn-link px-0 mt-2" href="<?= e(url('/safety')) ?>#badges">What badges don't guarantee <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
  </div>
</section>

<section class="section pt-0" aria-labelledby="safeTitle">
  <div class="feature-split">
    <div class="feature-img"><?= $pic('bright-bedroom', 'A bright bedroom with a window and bedside lamp') ?></div>
    <div>
      <span class="eyebrow"><i class="bi bi-shield-lock" aria-hidden="true"></i> Safety & privacy</span>
      <h2 class="section-title mt-2" id="safeTitle">Built for students moving somewhere new</h2>
      <ul class="check-list mt-4">
        <li><i class="bi bi-check-lg" aria-hidden="true"></i><div><strong>Your contact details stay private.</strong> No phone numbers, emails, documents or exact addresses on profiles.</div></li>
        <li><i class="bi bi-check-lg" aria-hidden="true"></i><div><strong>Chat only after a mutual yes.</strong> Matches and accepted enquiries unlock messaging — nobody can cold-message you.</div></li>
        <li><i class="bi bi-check-lg" aria-hidden="true"></i><div><strong>Report and block everywhere.</strong> On every profile, listing and message, reviewed by real moderators.</div></li>
        <li><i class="bi bi-check-lg" aria-hidden="true"></i><div><strong>You control your data.</strong> Pause discovery, download your data or delete your account any time.</div></li>
      </ul>
      <a class="btn btn-light mt-4" href="<?= e(url('/safety')) ?>">Visit the Safety centre</a>
    </div>
  </div>
</section>

<section class="section pt-0" aria-labelledby="citiesTitle">
  <span class="eyebrow">Cities</span><h2 class="section-title mt-2 mb-3" id="citiesTitle">Popular student cities</h2>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($cities as $c): ?><a class="city-chip" href="<?= e(url('/flatmates/' . $c['slug'])) ?>"><i class="bi bi-geo-alt" aria-hidden="true"></i><?= e($c['name']) ?><?= $c['listings'] ? ' <span class="badge text-bg-light border">' . (int)$c['listings'] . '</span>' : '' ?></a><?php endforeach; ?>
  </div>
</section>

<section class="section pt-0 hide-in-app" aria-labelledby="appTitle"><?= View::partial('partials/app_band', ['rel' => $rel, 'headingId' => 'appTitle']) ?></section>

<section class="section pt-0" aria-labelledby="faqTitle">
  <div class="row g-4">
    <div class="col-lg-4"><span class="eyebrow">FAQ</span><h2 class="section-title mt-2" id="faqTitle">Questions students ask</h2><p class="text-body-secondary">Something else? Email <a href="mailto:<?= e(setting('brand.support_email')) ?>"><?= e(setting('brand.support_email')) ?></a>.</p></div>
    <div class="col-lg-8 faq"><?php foreach ($faqs as [$q, $a]): ?><details><summary><?= e($q) ?></summary><div><?= e($a) ?></div></details><?php endforeach; ?></div>
  </div>
</section>

<section class="section pt-0">
  <div class="surface p-4 p-lg-5 text-center">
    <h2 class="section-title">Ready to find your people?</h2>
    <p class="section-lead mx-auto">Create a free profile in a few minutes. You can stay hidden until you're ready.</p>
    <a class="btn btn-primary btn-lg mt-2" href="<?= e(url('/register')) ?>">Find My Flatmate</a>
  </div>
</section>
