<div class="row justify-content-center"><div class="col-lg-8 content-page small">
<h1 class="h3">Terms of use</h1>
<p class="text-body-secondary">Last updated <?= e(date('j F Y', filemtime(__FILE__))) ?></p>
<ol>
<li><strong>Eligibility.</strong> You must be at least <?= (int)setting('rules.min_age', 18) ?> years old and a current or incoming college student, and give accurate information.</li>
<li><strong>Your account.</strong> Keep your password secret. One person, one account. We may restrict or suspend accounts that break these terms or our <a href="<?= e(url('/safety')) ?>">safety guidelines</a>.</li>
<li><strong>Listings.</strong> List only places you are entitled to rent or sub-let, with honest photos, prices and conditions. No brokerage or commercial listings disguised as student posts.</li>
<li><strong>Conduct.</strong> No harassment, discrimination, spam, scams, or sharing other people's personal data.</li>
<li><strong>No guarantee.</strong> <?= e(brand()) ?> is a platform to meet other students. We do not own, inspect or guarantee any property or person, and we are not a party to any rental agreement. Verification badges reduce, but do not remove, risk.</li>
<li><strong>Moderation.</strong> We may remove content and review reported messages to keep the community safe.</li>
<li><strong>Liability.</strong> To the extent permitted by law, we are not liable for losses arising from arrangements between users.</li>
<li><strong>Contact.</strong> <?= e(setting('brand.company')) ?> · <?= e(setting('brand.support_email')) ?>. These terms are governed by the laws of India.</li>
</ol></div></div>
