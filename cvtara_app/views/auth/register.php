<h1>Create a workspace</h1>
<?php include dirname(__DIR__) . '/partials/flash.php'; ?>
<form class="admin-form" method="post">
  <?= csrf_field() ?>
  <label>Full name<input class="form-control" name="name" required maxlength="120"></label>
  <label>Email<input class="form-control" type="email" name="email" required></label>
  <label>Mobile (for checkout)<input class="form-control" type="tel" name="phone" inputmode="numeric" maxlength="15" placeholder="10-digit Indian mobile"></label>
  <label>Password (12+ characters)<input class="form-control" type="password" name="password" minlength="12" required></label>
  <label>Experience
    <select class="form-control" name="experience_tier">
      <option value="fresher">Fresher</option>
      <option value="professional" selected>Professional</option>
      <option value="power">Power user</option>
    </select>
  </label>
  <label>Referral code (optional)<input class="form-control" name="referral" value="<?= e($_GET['ref'] ?? '') ?>"></label>
  <label class="switch-line"><span>Send me product updates</span><input type="checkbox" name="marketing"></label>
  <button class="button primary full">Create account</button>
</form>
<p>Already have an account? <a href="/login">Sign in</a></p>
