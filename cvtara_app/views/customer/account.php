<form class="panel panel-pad admin-form" method="post">
  <?= csrf_field() ?>
  <label>Name<input class="form-control" name="name" value="<?= e($user['name']) ?>"></label>
  <label>Phone<input class="form-control" name="phone" value="<?= e($user['phone'] ?? '') ?>"></label>
  <label>Profession<input class="form-control" name="profession" value="<?= e($user['profession'] ?? '') ?>"></label>
  <label>Experience
    <select class="form-control" name="experience_tier">
      <?php foreach (['fresher'=>'Fresher','professional'=>'Professional','power'=>'Power'] as $k=>$l): ?>
        <option value="<?= $k ?>" <?= $user['experience_tier']===$k?'selected':'' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="switch-line"><span>Product email (abandoned-scan follow-ups need this)</span><input type="checkbox" name="marketing" <?= !empty($user['marketing_consent'])?'checked':'' ?>></label>
  <button class="button primary">Save account</button>
</form>
<form class="panel panel-pad admin-form" method="post" action="/account/feedback" style="margin-top:16px">
  <?= csrf_field() ?>
  <h2>Share feedback</h2>
  <p>This goes to the owner. It is shown on the homepage only after they publish it.</p>
  <label>City<input class="form-control" name="city" maxlength="60"></label>
  <label>Rating
    <select class="form-control" name="rating"><option>5</option><option>4</option><option>3</option><option>2</option><option>1</option></select>
  </label>
  <label>Feedback<textarea class="form-control" name="feedback" minlength="20" maxlength="600" required></textarea></label>
  <label class="switch-line"><span>The owner may publish this with my name and role</span><input type="checkbox" name="consent" required></label>
  <button class="button primary">Send for review</button>
</form>
