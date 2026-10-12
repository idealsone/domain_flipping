<?php
// Detect if Cashfree payment session is ready to open
$cfSession = $_SESSION['cf_session'] ?? null;
$cfEnv     = $cashfreeEnv ?? 'sandbox';  // passed from controller
$openPay   = isset($_GET['pay']) && $cfSession;
?>
<div class="checkout-shell">

  <?php if ($openPay): ?>
  <!-- ── Cashfree Drop Checkout ───────────────────────────── -->
  <div class="checkout-card" style="text-align:center;padding:48px 32px">
    <div id="cfPayLoader">
      <div class="cf-spinner"></div>
      <p style="margin:18px 0 8px;font-weight:700;font-size:1.05rem">Opening secure payment…</p>
      <p style="font-size:.85rem;color:var(--lp-muted,#64748b)">Cashfree powers the checkout. CVTara does not see your card details.</p>
    </div>
    <div id="cfPayContainer" style="display:none;min-height:400px"></div>
    <p style="font-size:.78rem;color:var(--lp-muted,#64748b);margin-top:14px">
      After payment you will be redirected back here automatically. Do not close this tab.
    </p>
  </div>

  <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
  <script>
  (function () {
    const session = <?= json_encode($cfSession) ?>;
    const env     = <?= json_encode($cfEnv) ?>;
    const cashfree = Cashfree({ mode: env });

    cashfree.checkout({
      paymentSessionId: session,
      redirectTarget: '_self',
      appearance: { theme: 'minimal' },
    }).then(function (result) {
      if (result.error) {
        document.getElementById('cfPayLoader').innerHTML =
          '<p style="color:#f43f5e;font-weight:700">&#x26A0; Payment failed: ' + result.error.message + '</p>'
          + '<a href="/checkout" class="button secondary" style="margin-top:12px">Try again</a>';
      }
    }).catch(function () {
      document.getElementById('cfPayLoader').innerHTML =
        '<p style="color:#f43f5e;font-weight:700">&#x26A0; Could not open payment. Please try again.</p>'
        + '<a href="/checkout" class="button secondary" style="margin-top:12px">Go back</a>';
    });
  })();
  </script>

  <?php else: ?>
  <!-- ── Plan selection form ─────────────────────────────── -->
  <form class="checkout-grid" method="post" id="checkoutForm">
    <?= csrf_field() ?>
    <section class="checkout-card">
      <h2>Choose your access kit</h2>
      <?php foreach ($plans as $key => $plan): ?>
      <label class="switch-line">
        <span>
          <strong><?= e($plan['name']) ?></strong>
          <small>&nbsp;<?= money_inr($plan[$priceKey]) ?> · <?= (int) $plan['days'] ?> days</small>
        </span>
        <input type="radio" name="plan" value="<?= e($key) ?>" <?= $key === 'd60' ? 'checked' : '' ?>>
      </label>
      <?php endforeach; ?>

      <label style="margin-top:16px">Extra wording credits (₹19 each)
        <input class="form-control" type="range" name="credits" id="creditSlider" min="0" max="30" value="0">
        <output id="creditOut">0 extra credits</output>
      </label>

      <div class="secure-strip" style="margin-top:20px">
        <span>🔒 Card entry on Cashfree</span>
        <span>CVTara stores only order reference</span>
        <span>UPI · Cards · Net Banking · Wallets</span>
      </div>
    </section>

    <aside class="checkout-card">
      <h3>Pay securely via Cashfree</h3>
      <p style="font-size:.88rem;color:var(--lp-muted,#64748b);margin-bottom:20px">
        Your card details are entered on Cashfree's PCI-DSS certified payment page.
        CVTara receives only a payment reference after confirmation.
      </p>

      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:10px;font-size:.85rem">
          <span style="font-size:1.4rem">🔐</span>
          <span><strong>256-bit SSL</strong> — data encrypted in transit</span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:.85rem">
          <span style="font-size:1.4rem">🏦</span>
          <span><strong>UPI / Cards / Net Banking / Wallets</strong></span>
        </div>
        <div style="display:flex;align-items:center;gap:10px;font-size:.85rem">
          <span style="font-size:1.4rem">⚡</span>
          <span><strong>Instant access</strong> after payment confirmation</span>
        </div>
      </div>

      <button class="button primary full" type="submit">
        Proceed to payment →
      </button>
      <p style="font-size:.75rem;color:var(--lp-muted,#64748b);margin-top:10px;text-align:center">
        You will be redirected to Cashfree's secure payment page.
      </p>
    </aside>
  </form>
  <?php endif; ?>

</div>

<style>
.checkout-shell { max-width:860px; margin:0 auto; padding:32px 16px; }
.checkout-grid  { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.checkout-card  { background:var(--card-bg,#fff); border:1px solid var(--border,#e2e8f0); border-radius:16px; padding:28px; }
.switch-line    { display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid var(--border,#e2e8f0); cursor:pointer; }
.switch-line:last-of-type { border-bottom:none; }
.secure-strip   { display:flex; flex-wrap:wrap; gap:8px; }
.secure-strip span { font-size:.75rem; font-weight:700; padding:4px 12px; border-radius:999px; background:var(--soft,#f1f5f9); }
.button.full    { width:100%; justify-content:center; }
.cf-spinner     { width:36px; height:36px; border:3px solid #e2e8f0; border-top-color:#2563EB; border-radius:50%; animation:spin .7s linear infinite; margin:0 auto; }
@keyframes spin { to { transform:rotate(360deg); } }
@media(max-width:640px) { .checkout-grid { grid-template-columns:1fr; } }
</style>

<script>
const s = document.getElementById('creditSlider');
const o = document.getElementById('creditOut');
if (s && o) {
  const paint = () => o.textContent = s.value + ' extra credits · ₹' + (s.value * 19);
  s.addEventListener('input', paint);
  paint();
}
</script>
