<section class="contact-shell">
  <span class="eyebrow">Contact</span>
  <h1>Talk to the operator, not a script.</h1>
  <div class="contact-grid">
    <aside class="panel panel-pad">
      <p><?= e($legal['footerText'] ?? 'Support hours and address are set in the owner console.') ?></p>
      <p><strong><?= e($legal['supportEmail'] ?? 'Add a support email') ?></strong></p>
      <p><?= e($legal['address'] ?? '') ?></p>
    </aside>
    <form class="panel panel-pad admin-form" method="post">
      <?= csrf_field() ?>
      <?php include dirname(__DIR__) . '/partials/flash.php'; ?>
      <label>Name<input class="form-control" name="name" required></label>
      <label>Email<input class="form-control" name="email" type="email" required></label>
      <label>Message<textarea class="form-control" name="message" required minlength="10"></textarea></label>
      <label class="switch-line"><span>Also add me to product updates (opt-in)</span><input type="checkbox" name="updates"></label>
      <button class="button primary">Send</button>
    </form>
  </div>
</section>
