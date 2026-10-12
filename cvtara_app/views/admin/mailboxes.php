<p>Keep one transactional mailbox on your main domain for receipts and password resets. Add extra marketing mailboxes (Google Workspace or Hostinger) and campaigns will rotate through the enabled ones.</p>
<div class="owner-table-scroll">
<table class="admin-table">
  <thead><tr><th>Label</th><th>Purpose</th><th>From</th><th>Host</th><th>Enabled</th><th>Last used</th></tr></thead>
  <tbody>
  <?php foreach ($boxes as $b): ?>
    <tr>
      <td><?= e($b['label']) ?></td>
      <td><?= e($b['purpose']) ?></td>
      <td><?= e($b['from_name']) ?> &lt;<?= e($b['from_email']) ?>&gt;</td>
      <td><?= e($b['host']) ?>:<?= (int) $b['port'] ?></td>
      <td><?= $b['enabled'] ? 'Yes' : 'No' ?></td>
      <td><?= e($b['last_used_at'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<form class="panel panel-pad admin-form" method="post" style="margin-top:16px">
  <?= csrf_field() ?>
  <h3>Add or update a mailbox</h3>
  <label>Existing ID to update (blank = new)<input class="form-control" name="id" type="number" min="0"></label>
  <div class="form-row">
    <label>Label<input class="form-control" name="label" required></label>
    <label>Purpose<select class="form-control" name="purpose"><option value="marketing">Marketing</option><option value="transactional">Transactional</option></select></label>
  </div>
  <div class="form-row">
    <label>SMTP host<input class="form-control" name="host" required></label>
    <label>Port<input class="form-control" name="port" type="number" value="587"></label>
  </div>
  <div class="form-row">
    <label>Username<input class="form-control" name="username" required></label>
    <label>Password<input class="form-control" name="password" type="password" autocomplete="new-password"></label>
  </div>
  <div class="form-row">
    <label>From name<input class="form-control" name="from_name" value="CVTara"></label>
    <label>From email<input class="form-control" name="from_email" type="email" required></label>
  </div>
  <label>Encryption<select class="form-control" name="encryption"><option>tls</option><option>ssl</option><option>none</option></select></label>
  <label class="switch-line"><span>Enabled</span><input type="checkbox" name="enabled" checked></label>
  <button class="button primary">Save mailbox</button>
</form>
