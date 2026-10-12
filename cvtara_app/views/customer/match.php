<div class="match-layout">
  <form class="panel panel-pad admin-form" method="post">
    <?= csrf_field() ?>
    <label>Role<input class="form-control" name="job_title"></label>
    <label>Job description<textarea class="form-control" name="job" required></textarea></label>
    <label>Resume text<textarea class="form-control" name="resume" required></textarea></label>
    <button class="button primary">Compare</button>
  </form>
  <section class="panel result-panel">
    <div class="result-inner">
      <?php if ($report): ?>
        <div class="result-score"><strong><?= (int) $report['score'] ?></strong><span>overlap</span></div>
        <div class="progress-bars">
          <?php foreach ($report['missing'] as $w): ?><div><?= e($w) ?><i style="--w:30%"></i></div><?php endforeach; ?>
        </div>
        <p><?= e(implode(' ', $report['notes'])) ?></p>
      <?php else: ?>
        <p>Recent scans:</p>
        <?php foreach ($scans as $s): ?><p><?= e($s['job_title'] ?: 'Scan') ?> · <?= (int) $s['score'] ?> · <?= e($s['created_at']) ?></p><?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</div>
