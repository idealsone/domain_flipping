<section class="page-hero">
  <span class="eyebrow">HR analytics engine</span>
  <h1>See the overlap before you apply.</h1>
  <p>Paste the job description and your current resume. The scan reports missing keyword groups, action-verb density and a 0–100 compliance score. It does not invent experience.</p>
</section>
<section class="section" style="padding-top:0">
  <?php include dirname(__DIR__) . '/partials/flash.php'; ?>
  <div class="diagnosis">
    <form class="panel panel-pad admin-form" method="post">
      <?= csrf_field() ?>
      <label>Role title<input class="form-control" name="job_title" value="<?= e($_POST['job_title'] ?? '') ?>"></label>
      <label>Job description<textarea class="form-control" name="job" required minlength="40"><?= e($job ?? '') ?></textarea></label>
      <label>Resume text<textarea class="form-control" name="resume" required minlength="40"><?= e($resume ?? '') ?></textarea></label>
      <button class="button primary">Run compliance scan</button>
    </form>
    <div class="panel result-panel">
      <div class="result-inner">
        <?php if ($report): ?>
          <span class="eyebrow">Result</span>
          <div class="result-score"><strong><?= (int) $report['score'] ?></strong><span>overlap score</span></div>
          <div class="gap-list">
            <?php foreach ($report['missing'] as $word): ?><div><?= e($word) ?><i>missing</i></div><?php endforeach; ?>
          </div>
          <p><?= e(implode(' ', $report['notes'])) ?></p>
          <a class="button mint" href="/register">Save this in a workspace</a>
        <?php else: ?>
          <p>Your score and missing language will appear here.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
