<h1>Sign in</h1>
<?php include dirname(__DIR__) . '/partials/flash.php'; ?>
<form class="admin-form" method="post">
  <?= csrf_field() ?>
  <label>Email<input class="form-control" type="email" name="email" required></label>
  <label>Password<input class="form-control" type="password" name="password" required></label>
  <button class="button primary full">Continue</button>
</form>
<p><a href="/forgot">Forgot password</a> · <a href="/register">Create account</a></p>
