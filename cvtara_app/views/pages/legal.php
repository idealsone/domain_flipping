<section class="legal-shell">
  <h1><?= e($title) ?></h1>
  <?php foreach (preg_split("/\n\s*\n/", trim($body)) as $p): ?>
    <p><?= nl2br(e($p)) ?></p>
  <?php endforeach; ?>
</section>
