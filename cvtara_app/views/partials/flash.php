<?php foreach (\App\Support\Response::flashes() as $flash): ?>
<p class="admin-note" role="status"><?= e($flash['message']) ?></p>
<?php endforeach; ?>
