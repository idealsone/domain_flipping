<section class="panel panel-pad">
  <h2>Homepage feedback</h2>
  <p>Publish sends the note to the public homepage. Hide removes it. Customers submit this from Account or from the public feedback form.</p>
  <?php if (!$items): ?><p>No feedback yet.</p><?php endif; ?>
  <?php foreach ($items as $item): ?>
    <article style="margin:16px 0;padding-top:12px;border-top:1px solid var(--line,#e2e8f0)">
      <h3><?= e($item['name']) ?> · <?= (int) $item['rating'] ?>/5 · <?= e($item['status']) ?></h3>
      <p><?= e($item['city']) ?> · <?= e($item['role']) ?> · <?= e($item['source']) ?></p>
      <p><?= e($item['text']) ?></p>
      <form method="post" action="/admin/reviews" class="top-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($item['id']) ?>">
        <button class="button primary" name="status" value="published">Publish on homepage</button>
        <button class="button secondary" name="status" value="hidden">Hide</button>
        <button class="button ghost" name="status" value="pending">Pending</button>
      </form>
    </article>
  <?php endforeach; ?>
</section>
