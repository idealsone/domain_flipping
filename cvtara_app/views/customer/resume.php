<?php $premium = is_premium($user); ?>
<form class="resume-editor" method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) ($resume['id'] ?? 0) ?>">
  <div class="editor-fields panel panel-pad">
    <label>Name<input class="form-control" name="name" value="<?= e($content['name'] ?? '') ?>"></label>
    <label>Professional title<input class="form-control" name="role" value="<?= e($content['role'] ?? '') ?>"></label>
    <label>Summary<textarea class="form-control" name="summary"><?= e($content['summary'] ?? '') ?></textarea></label>
    <label>Experience (one fact per line)<textarea class="form-control" name="experience"><?= e($content['experience'] ?? '') ?></textarea></label>
    <label>Education<textarea class="form-control" name="education"><?= e($content['education'] ?? '') ?></textarea></label>
    <label>Skills<textarea class="form-control" name="skills"><?= e($content['skills'] ?? '') ?></textarea></label>
    <button class="button primary">Save document</button>
    <button class="button secondary" type="button" onclick="window.print()">Print / save PDF</button>
    <?php if (!$premium): ?>
      <p>Free exports include a CVTara mark. <a href="/checkout">Upgrade</a> to download a clean copy.</p>
    <?php endif; ?>
  </div>
  <div class="editor-preview">
    <article class="resume-sheet design-clarity <?= $premium ? '' : 'is-watermarked' ?>" style="--resume-accent:#1557d2">
      <header class="resume-header"><span class="resume-kicker"><?= e($content['role'] ?? 'TITLE') ?></span><h3><?= e($content['name'] ?? 'NAME') ?></h3></header>
      <section class="resume-section"><h5>SUMMARY</h5><p><?= e($content['summary'] ?? '') ?></p></section>
      <section class="resume-section"><h5>EXPERIENCE</h5><p><?= nl2br(e($content['experience'] ?? '')) ?></p></section>
      <section class="resume-section"><h5>EDUCATION</h5><p><?= nl2br(e($content['education'] ?? '')) ?></p></section>
      <section class="resume-section"><h5>SKILLS</h5><p><?= nl2br(e($content['skills'] ?? '')) ?></p></section>
    </article>
  </div>
</form>
<style>
.resume-sheet { position: relative; }
.resume-sheet.is-watermarked::after {
  content: "CVTara preview";
  position: absolute;
  inset: 28% 8%;
  display: grid;
  place-items: center;
  font-size: 2.4rem;
  font-weight: 800;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: rgba(15, 23, 42, .12);
  transform: rotate(-22deg);
  pointer-events: none;
}
@media print {
  .workspace-shell aside, .workspace-top, .editor-fields, .flash, .top-actions { display: none !important; }
  .workspace-main, .workspace-content, .resume-editor, .editor-preview { display: block; width: 100%; margin: 0; padding: 0; box-shadow: none; }
  .resume-sheet { box-shadow: none; }
}
</style>
