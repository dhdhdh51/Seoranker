<?php if (!empty($currentUser)): ?>
<div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="<?= e(url('/report')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="target_type" value=""><input type="hidden" name="target_id" value="">
    <div class="modal-header"><h2 class="modal-title fs-5" id="reportTitle"><i class="bi bi-flag me-1"></i>Report</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p class="small text-body-secondary">Reports are confidential — the other person is not told who reported them. If you are in immediate danger call <strong>112</strong>.</p>
      <label class="form-label" for="reportReason">What's wrong?</label>
      <select class="form-select mb-3" id="reportReason" name="reason" required>
        <option value="">Choose a reason…</option>
        <?php foreach (\Flatmates\Support\Options::GROUPS['report_reason'] as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?>
      </select>
      <label class="form-label" for="reportDetails">Details (optional)</label>
      <textarea class="form-control" id="reportDetails" name="details" rows="3" maxlength="2000" placeholder="What happened? Moderators will see this."></textarea>
      <div class="form-check mt-3" data-block-option><input class="form-check-input" type="checkbox" name="block" value="1" id="reportBlock"><label class="form-check-label" for="reportBlock">Also block this person</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Send report</button></div>
  </form></div>
</div>
<?php endif; ?>
