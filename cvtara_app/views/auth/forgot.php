<h1>Reset password</h1>
<?php include dirname(__DIR__) . '/partials/flash.php'; ?>
<form class="admin-form" method="post">
  <?= csrf_field() ?>
  <label>Email<input class="form-control" type="email" name="email" required></label>
  <button class="button primary full">Send reset link</button>
</form>
