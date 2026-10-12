<section class="metric-grid">
  <article class="metric-card"><span>Available</span><b><?= money_inr($available) ?></b></article>
  <article class="metric-card"><span>In 14-day hold</span><b><?= money_inr($held) ?></b></article>
  <article class="metric-card"><span>Withdrawn</span><b><?= money_inr($withdrawn) ?></b></article>
  <article class="metric-card"><span>Your link</span><b><?= e($code) ?></b></article>
</section>
<p>Share <?= e(url('/register?ref=' . $code)) ?>. Commission stays on hold for 14 days to cover payment reversals. Minimum withdrawal is ₹100 to UPI or a bank account via Cashfree.</p>

<form class="panel panel-pad admin-form" method="post" action="/wallet/withdraw" id="withdrawForm" style="margin:20px 0">
  <?= csrf_field() ?>
  <h3>Withdraw available balance</h3>
  <label>Amount (₹)
    <input class="form-control" type="number" name="amount" min="100" step="1" max="<?= max(0, (int) floor($available / 100)) ?>" value="<?= max(0, (int) floor($available / 100)) ?>" required>
  </label>
  <label>Transfer mode
    <select class="form-control" name="mode" id="withdrawMode">
      <option value="upi">UPI</option>
      <option value="banktransfer">Bank transfer</option>
    </select>
  </label>
  <label>UPI ID or bank account number
    <input class="form-control" name="upi_or_account" placeholder="name@upi or account number" required>
  </label>
  <label id="ifscLabel" hidden>IFSC
    <input class="form-control" name="ifsc" placeholder="HDFC0001234" maxlength="11">
  </label>
  <?php if ($available < 10000): ?>
    <p>Available balance is below ₹100, so a withdrawal cannot be sent yet.</p>
    <button class="button primary" type="submit" disabled>Withdraw</button>
  <?php else: ?>
    <button class="button primary" type="submit">Send withdrawal</button>
  <?php endif; ?>
</form>

<table class="admin-table">
  <thead><tr><th>When</th><th>Type</th><th>Amount</th><th>Status</th><th>Note</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= e($r['created_at']) ?></td><td><?= e($r['type']) ?></td><td><?= money_inr((int) $r['amount_paise']) ?></td><td><?= e($r['status']) ?></td><td><?= e($r['note']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="5">No wallet activity yet. Share your referral link after a paid signup.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
<script>
(function () {
  const mode = document.getElementById('withdrawMode');
  const ifsc = document.getElementById('ifscLabel');
  if (!mode || !ifsc) return;
  const paint = () => {
    const bank = mode.value === 'banktransfer';
    ifsc.hidden = !bank;
    ifsc.querySelector('input').required = bank;
  };
  mode.addEventListener('change', paint);
  paint();
})();
</script>
