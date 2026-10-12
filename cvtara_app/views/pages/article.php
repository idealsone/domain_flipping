<article class="article-shell">
  <nav class="toc">
    <b>On this page</b>
    <a href="#answer">Direct answer</a>
    <a href="#tool">Try the numbers</a>
    <a href="#related">Related</a>
  </nav>
  <div class="article">
    <span class="eyebrow"><?= e($item['category'] ?? '') ?></span>
    <h1><?= e($item['title']) ?></h1>
    <p class="answer-first" id="answer"><?= e($item['description'] ?? '') ?></p>
    <?php if (!empty($item['body'])): ?>
      <div><?= nl2br(e($item['body'])) ?></div>
    <?php else: ?>
      <h2 id="tool">Use the figures you already have</h2>
      <p>This page is a planning aid. Replace every default with numbers from your offer letter, payslip or research notes. CVTara does not submit filings or speak for an employer.</p>
      <div class="calculator-box">
        <form class="admin-form" onsubmit="event.preventDefault();const a=Number(this.a.value||0),b=Number(this.b.value||0);this.querySelector('output').textContent=Math.round(a*(1-b/100)).toLocaleString('en-IN');">
          <div class="calculator-fields">
            <label>Primary amount<input class="form-control" name="a" type="number" min="0" value="1200000"></label>
            <label>Adjustment %<input class="form-control" name="b" type="number" min="0" max="80" value="30"></label>
          </div>
          <button class="button primary">Estimate</button>
          <p class="calculator-result">Illustrative result <output>—</output></p>
        </form>
      </div>
    <?php endif; ?>
    <div class="conversion-callout">
      <div><span class="eyebrow">Workspace</span><h2>Turn this into a saved document.</h2><p>Keep the same facts, then compare them with a live job description.</p></div>
      <a class="button mint" href="/register">Open workspace</a>
    </div>
    <h2 id="related">Related</h2>
    <p>Also useful: <?php foreach ($related as $r): ?><a href="/<?= $item['type']==='Calculator'?'guides':'calculators' ?>/<?= e($r[0]) ?>"><?= e($r[1]) ?></a>. <?php endforeach; ?>Or run a <a href="/scan">compliance scan</a>.</p>
  </div>
</article>
