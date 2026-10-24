<?php if ($done): ?>
  <h1 class="h4">You have been unsubscribed</h1>
  <p class="text-body-secondary">We will not contact this address again. Thank you for letting us know.</p>
<?php elseif (!$valid): ?>
  <h1 class="h4">Link not recognised</h1>
  <p class="text-body-secondary">This unsubscribe link is invalid. You can also reply “unsubscribe” to the email you received and we will remove you.</p>
<?php else: ?>
  <h1 class="h4">Unsubscribe</h1>
  <p class="text-body-secondary">Confirm that you no longer want to receive outreach emails sent through <?= e(brand()) ?>.</p>
  <form method="post" action="<?= e(url('/unsubscribe/' . $token)) ?>">
    <button class="btn btn-danger w-100" type="submit">Unsubscribe me</button>
  </form>
<?php endif; ?>
