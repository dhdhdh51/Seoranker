<div class="page-head"><div><h1>Messages</h1><p>Private conversations with your matches and listing owners.</p></div></div>
<?php if (!$conversations): ?>
  <div class="surface"><?= \Flatmates\Http\View::partial('partials/empty', ['icon' => 'chat-dots', 'title' => 'No conversations yet', 'text' => 'Chats open when you and another student match, or when an owner accepts your enquiry.', 'cta' => ['Discover flatmates', '/discover']]) ?></div>
<?php else: ?>
  <div class="surface overflow-hidden chat-list d-block border-0"><?= \Flatmates\Http\View::partial('chat/_list', ['conversations' => $conversations]) ?></div>
<?php endif; ?>
