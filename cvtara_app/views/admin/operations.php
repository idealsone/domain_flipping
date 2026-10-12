<section class="panel panel-pad">
  <h3>Maintenance shield</h3>
  <p>Your current IP is <code><?= e($ip) ?></code>. Add it to the whitelist so you can keep testing while customers see the maintenance page. Login, owner pages and payment webhooks stay reachable.</p>
  <form class="admin-form" method="post" action="/admin/operations/maintenance">
    <?= csrf_field() ?>
    <label class="switch-line"><span>Enable maintenance for customers</span><input type="checkbox" name="enabled" <?= !empty($maintenance['enabled'])?'checked':'' ?>></label>
    <label>Heading<input class="form-control" name="title" value="<?= e($maintenance['title'] ?? '') ?>" required></label>
    <label>Message<textarea class="form-control" name="message" required><?= e($maintenance['message'] ?? '') ?></textarea></label>
    <label>Whitelisted IPs (comma-separated)<input class="form-control" name="whitelist" value="<?= e(implode(',', $maintenance['whitelist'] ?? [])) ?>"></label>
    <button class="button primary">Save maintenance</button>
  </form>
</section>
<section class="panel panel-pad" style="margin-top:16px">
  <h3>Cache</h3>
  <form method="post" action="/admin/operations/cache/clear"><?= csrf_field() ?><button class="button secondary">Clear application cache</button></form>
</section>
<section class="panel panel-pad" style="margin-top:16px">
  <h3>Error log</h3>
  <form class="owner-editor-toolbar" method="get">
    <input type="search" name="q" value="<?= e($query) ?>" placeholder="Filter text">
    <select name="level">
      <option value="">All levels</option>
      <?php foreach (['error','warning','info','debug'] as $lvl): ?>
        <option <?= $level===$lvl?'selected':'' ?>><?= $lvl ?></option>
      <?php endforeach; ?>
    </select>
    <button class="button secondary">Filter</button>
  </form>
  <form method="post" action="/admin/operations/logs/clear" style="margin:12px 0"><?= csrf_field() ?><button class="button secondary">Clear log</button></form>
  <pre class="panel-pad" style="max-height:420px;overflow:auto;background:#0f172a;color:#cbd5e1;border-radius:12px"><?php if (!$logs): ?>No matching entries.<?php endif; ?><?php foreach ($logs as $line): ?><?= e($line) . "\n" ?><?php endforeach; ?></pre>
</section>
