<section class="hero">
  <div class="hero-shell">
    <div class="hero-copy">
      <span class="eyebrow">Career document workspace</span>
      <h1>A finished page. A real gap report. A next action.</h1>
      <p>CVTara is a structured resume workspace with a compliance scan, evidence-first wording and an application tracker. No invented history. No ranking promises.</p>
      <div class="hero-actions">
        <a class="button primary" href="/register">Create a workspace</a>
        <a class="button secondary" href="/scan">Run a free scan</a>
      </div>
      <div class="trust-row">
        <span>Facts you already have</span>
        <span>0–100 overlap score</span>
        <span>Unsubscribe on every marketing note</span>
      </div>
    </div>
    <div class="hero-visual">
      <div class="product-window">
        <div class="window-bar"><i></i><i></i><i></i><span>Compliance matrix</span></div>
        <div class="product-body">
          <aside class="mini-side"><b>C</b><i class="active"></i><i></i></aside>
          <div class="report">
            <div class="report-head"><div><span>Role fit</span><b>Talent acquisition specialist</b></div><span class="status-chip">Explainable</span></div>
            <div class="score-wrap">
              <div class="score-ring"><div><b>78</b><small>overlap</small></div></div>
              <div class="crucial"><span>Missing language</span><b>Add ATS, campus hiring and joining visibility only if you can evidence them.</b></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<section class="section">
  <div class="section-heading"><div><span class="eyebrow">How it works</span><h2>Four steps, one evidence bank.</h2></div></div>
  <div class="roadmap">
    <article class="road-card active"><span>1</span><h3>Capture facts</h3><p>Name the role, dates and results you can stand behind.</p></article>
    <article class="road-card"><span>2</span><h3>Scan the posting</h3><p>See missing keyword groups and action-verb density.</p></article>
    <article class="road-card"><span>3</span><h3>Tighten wording</h3><p>Optimization spends a credit and never invents employers or numbers.</p></article>
    <article class="road-card"><span>4</span><h3>Track follow-up</h3><p>Company, stage and next action stay with the document version.</p></article>
    <article class="road-card"><span>5</span><h3>Share if you want</h3><p>Referral commission sits in a 14-day hold after a verified purchase.</p></article>
  </div>
</section>
<section class="section">
  <div class="section-heading"><div><span class="eyebrow">Plans</span><h2>Pay for time, kits and credits.</h2></div><a class="button secondary" href="/pricing">Full pricing</a></div>
  <div class="pricing-grid">
    <?php foreach ($plans as $key => $plan): ?>
    <article class="price-card <?= $key === 'd60' ? 'featured' : '' ?>">
      <?php if ($key === 'd60'): ?><span class="popular">Most used</span><?php endif; ?>
      <span><?= strtoupper($key) ?></span>
      <h3><?= e($plan['name']) ?></h3>
      <div class="price"><?= money_inr($plan['price']) ?><small> experienced</small></div>
      <p>Fresher price <?= money_inr($plan['fresherPrice']) ?> · <?= (int) $plan['days'] ?> days · <?= (int) $plan['credits'] ?> optimize credits</p>
      <a class="button primary full" href="/register">Start</a>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<section class="section">
  <div class="section-heading"><div><span class="eyebrow">Guides & tools</span><h2>Useful pages, linked from the workspace.</h2></div></div>
  <div class="blog-grid">
    <?php foreach (array_merge($tools, $guides) as $i => $row): $kind = $i < count($tools) ? 'calculators' : 'guides'; ?>
    <article class="blog-card">
      <a class="blog-art" href="/<?= $kind ?>/<?= e($row[0]) ?>"><span><?= e($row[2]) ?></span><b><?= e(substr($row[1], 0, 18)) ?></b></a>
      <div class="blog-copy"><h3><a href="/<?= $kind ?>/<?= e($row[0]) ?>"><?= e($row[1]) ?></a></h3><p><?= e($row[3]) ?></p></div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
