<section class="page-hero">
  <span class="eyebrow">Library</span>
  <h1><?= e($heading) ?></h1>
  <p>Each page answers one question and links back to the salary tools or the workspace.</p>
</section>
<section class="section" style="padding-top:0">
  <div class="blog-grid">
    <?php foreach ($items as $row): ?>
    <article class="blog-card">
      <a class="blog-art" href="/<?= e($kind) ?>/<?= e($row[0]) ?>"><span><?= e($row[2]) ?></span><b><?= e(substr($row[1], 0, 16)) ?></b></a>
      <div class="blog-copy"><h3><a href="/<?= e($kind) ?>/<?= e($row[0]) ?>"><?= e($row[1]) ?></a></h3><p><?= e($row[3]) ?></p></div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
