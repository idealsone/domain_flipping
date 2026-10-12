<p>Remaining credits: <strong><?= (int) ($credits['remaining_balance'] ?? 0) ?></strong> of <?= (int) ($credits['total_allowed'] ?? 0) ?>. Double-submit is blocked with a row lock.</p>
<form class="panel panel-pad admin-form" method="post">
  <?= csrf_field() ?>
  <label>Career text to tighten<textarea class="form-control" name="text" required><?= e($result['text'] ?? '') ?></textarea></label>
  <label>Optional job language to mirror only if already evidenced<textarea class="form-control" name="job"></textarea></label>
  <button class="button primary">Run handcrafted optimization</button>
</form>
<?php if ($result): ?>
<section class="panel panel-pad"><h3>Updated wording</h3><p><?= nl2br(e($result['text'])) ?></p></section>
<?php endif; ?>
