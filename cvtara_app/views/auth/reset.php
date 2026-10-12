<h1>Choose a new password</h1>
<form class="admin-form" method="post">
  <?= csrf_field() ?>
  <label>New password<input class="form-control" type="password" name="password" minlength="12" required></label>
  <button class="button primary full">Update password</button>
</form>
