<?php
$p = $project ?? [];
$v = static fn(string $k, string $default = '') => old($k, $p[$k] ?? $default);
$err = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$fb = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback">' . e($errors[$k]) . '</div>' : '';
?>
<div class="page-head"><h1 class="h3 mb-0"><?= e($title) ?></h1></div>
<form method="post" action="<?= e(url($project ? '/projects/' . $project['id'] : '/projects')) ?>">
<?= csrf_field() ?>
<div class="row g-3">
  <div class="col-xl-8">
    <div class="card card-soft mb-3"><div class="card-body row g-3">
      <h2 class="h6 text-uppercase text-body-secondary mb-0">Project</h2>
      <div class="col-md-6"><label class="form-label" for="client_id">Client *</label>
        <select id="client_id" name="client_id" class="form-select<?= $err('client_id') ?>" <?= $project ? 'disabled' : 'required' ?>>
          <?php foreach ($clients as $id => $name): ?><option value="<?= (int)$id ?>"<?= selected(old('client_id', $preselect ?? ''), $id) ?>><?= e($name) ?></option><?php endforeach; ?>
        </select><?= $fb('client_id') ?></div>
      <div class="col-md-6"><label class="form-label" for="name">Project name *</label><input id="name" class="form-control<?= $err('name') ?>" name="name" value="<?= e($v('name')) ?>" required maxlength="160"><?= $fb('name') ?></div>
      <div class="col-md-8"><label class="form-label" for="website_url">Website URL *</label><input id="website_url" class="form-control<?= $err('website_url') ?>" name="website_url" value="<?= e($v('website_url')) ?>" required placeholder="https://www.example.in/"><?= $fb('website_url') ?><div class="form-text">The normalized domain must be unique per client. Changing it resets ownership verification.</div></div>
      <div class="col-md-4"><label class="form-label" for="niche">Niche</label><input id="niche" class="form-control" name="niche" value="<?= e($v('niche')) ?>" maxlength="160" placeholder="e.g. organic farming"></div>
      <div class="col-12"><label class="form-label" for="business_description">Business description</label><textarea id="business_description" class="form-control" name="business_description" rows="3" maxlength="5000"><?= e($v('business_description')) ?></textarea></div>
      <div class="col-md-6"><label class="form-label" for="target_countries">Target countries</label><input id="target_countries" class="form-control" name="target_countries" value="<?= e($v('target_countries', 'India')) ?>" maxlength="500"></div>
      <div class="col-md-6"><label class="form-label" for="target_languages">Target languages</label><input id="target_languages" class="form-control" name="target_languages" value="<?= e($v('target_languages', 'English, Hindi')) ?>" maxlength="255"></div>
    </div></div>
    <div class="card card-soft mb-3"><div class="card-body row g-3">
      <h2 class="h6 text-uppercase text-body-secondary mb-0">Targeting</h2>
      <div class="col-md-6"><label class="form-label" for="keywords">Relevant keywords (one per line)</label><textarea id="keywords" class="form-control" name="keywords" rows="5"><?= e(old('keywords', $keywords)) ?></textarea></div>
      <div class="col-md-6"><label class="form-label" for="topics">Topics (one per line)</label><textarea id="topics" class="form-control" name="topics" rows="5"><?= e(old('topics', $topics)) ?></textarea></div>
      <div class="col-12"><label class="form-label" for="target_pages">Target landing pages (one URL per line, on the project domain)</label><textarea id="target_pages" class="form-control<?= $err('target_pages') ?>" name="target_pages" rows="4"><?= e(old('target_pages', $targets)) ?></textarea><?= $fb('target_pages') ?></div>
      <div class="col-md-6"><label class="form-label" for="brand_name">Brand name</label><input id="brand_name" class="form-control" name="brand_name" value="<?= e($v('brand_name')) ?>" maxlength="160"></div>
      <div class="col-md-6"><label class="form-label" for="preferred_anchors">Preferred natural anchors (one per line)</label><textarea id="preferred_anchors" class="form-control" name="preferred_anchors" rows="3" placeholder="Brand name&#10;brand.in&#10;this guide"><?= e($v('preferred_anchors')) ?></textarea><div class="form-text">Keyword-heavy commercial anchors are flagged and never auto-suggested.</div></div>
      <div class="col-12"><label class="form-label" for="exclusions">Project exclusions (domains, one per line)</label><textarea id="exclusions" class="form-control" name="exclusions" rows="3" placeholder="competitor.com&#10;spammy-directory.net"><?= e($v('exclusions')) ?></textarea></div>
    </div></div>
  </div>
  <div class="col-xl-4">
    <div class="card card-soft mb-3"><div class="card-body row g-3">
      <h2 class="h6 text-uppercase text-body-secondary mb-0">Outreach identity</h2>
      <div class="col-12"><label class="form-label" for="outreach_from_name">Sender name</label><input id="outreach_from_name" class="form-control" name="outreach_from_name" value="<?= e($v('outreach_from_name')) ?>" maxlength="160"></div>
      <div class="col-12"><label class="form-label" for="contact_email">Reply-to / contact email</label><input id="contact_email" type="email" class="form-control<?= $err('contact_email') ?>" name="contact_email" value="<?= e($v('contact_email')) ?>" maxlength="190"><?= $fb('contact_email') ?></div>
      <div class="col-12"><label class="form-label" for="outreach_signature">Signature</label><textarea id="outreach_signature" class="form-control" name="outreach_signature" rows="4" maxlength="2000"><?= e($v('outreach_signature')) ?></textarea></div>
      <div class="col-12"><label class="form-label" for="approval_policy">Outreach approval policy</label>
        <select id="approval_policy" class="form-select" name="approval_policy">
          <option value="manager"<?= selected($v('approval_policy', 'manager'), 'manager') ?>>SEO manager approves</option>
          <option value="client"<?= selected($v('approval_policy'), 'client') ?>>Client approves</option>
          <option value="manager_and_client"<?= selected($v('approval_policy'), 'manager_and_client') ?>>Manager and client both approve</option>
        </select>
        <div class="form-text">No email is ever sent without the approvals required here.</div>
      </div>
    </div></div>
    <div class="d-grid gap-2"><button class="btn btn-primary btn-lg">Save project</button><a class="btn btn-outline-secondary" href="<?= e(url($project ? '/projects/' . $project['id'] : '/projects')) ?>">Cancel</a></div>
  </div>
</div>
</form>
