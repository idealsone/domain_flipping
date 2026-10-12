<p>Import only people who opted in or whose consent you recorded. CVTara does not scrape LinkedIn. A LinkedIn export is allowed only when those contacts already agreed to hear from you.</p>
<div class="admin-grid">
  <form class="panel panel-pad admin-form" method="post" action="/admin/leads/add">
    <?= csrf_field() ?>
    <h3>Add one opt-in</h3>
    <label>Name<input class="form-control" name="name"></label>
    <label>Email<input class="form-control" name="email" type="email" required></label>
    <button class="button primary">Add lead</button>
  </form>
  <form class="panel panel-pad admin-form" method="post" action="/admin/leads/import" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h3>CSV import</h3>
    <label>Source<select class="form-control" name="source"><?php foreach ($sources as $k=>$l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label>Consent evidence<input class="form-control" name="consent_note" required placeholder="e.g. Webinar 12 Oct checkbox"></label>
    <label>CSV file<input class="form-control" type="file" name="file" accept=".csv,text/csv"></label>
    <label>Or paste CSV<textarea class="form-control" name="csv" placeholder="email,name"></textarea></label>
    <button class="button primary">Import</button>
  </form>
</div>
<div class="owner-actions" style="margin:16px 0;display:flex;gap:8px">
  <form method="post" action="/admin/leads/outreach"><?= csrf_field() ?><button class="button secondary">Run consented outreach now</button></form>
  <form method="post" action="/admin/leads/abandoned"><?= csrf_field() ?><button class="button secondary">Send abandoned-scan notes</button></form>
</div>
<div class="owner-table-scroll">
<table class="admin-table">
  <thead><tr><th>Email</th><th>Source</th><th>Step</th><th>Stopped</th><th>Last sent</th></tr></thead>
  <tbody>
  <?php foreach ($leads as $l): ?>
    <tr>
      <td><?= e($l['name'] ? $l['name'].' · ' : '') ?><?= e($l['email']) ?></td>
      <td><?= e($l['source']) ?></td>
      <td><?= (int) $l['campaign_step'] ?></td>
      <td><?= $l['stop_marketing'] ? 'Yes' : 'No' ?></td>
      <td><?= e($l['last_sent_at'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
