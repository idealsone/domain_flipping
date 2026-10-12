<form class="panel panel-pad admin-form" method="post" style="margin-bottom:18px">
  <?= csrf_field() ?>
  <div class="form-row">
    <label>Company<input class="form-control" name="company" required></label>
    <label>Role<input class="form-control" name="role" required></label>
  </div>
  <div class="form-row">
    <label>Stage<select class="form-control" name="stage"><option>saved</option><option>applied</option><option>interview</option><option>offer</option></select></label>
    <label>Next action<input class="form-control" name="next_action"></label>
  </div>
  <button class="button primary">Add application</button>
</form>
<div class="kanban">
  <?php foreach (['saved','applied','interview','offer'] as $stage): ?>
  <section class="kanban-col">
    <span><?= strtoupper($stage) ?></span>
    <?php foreach ($apps as $a): if ($a['stage'] !== $stage) continue; ?>
      <article class="job-card"><b><?= e($a['company']) ?></b><small><?= e($a['role']) ?><br><?= e($a['next_action'] ?? '') ?></small></article>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
</div>
