<section class="page-hero">
  <span class="eyebrow">Pricing</span>
  <h1>Job kits with visible allowances.</h1>
  <p>Freshers and experienced professionals see different prices after sign-in. Card details are entered on Razorpay. CVTara does not store card numbers.</p>
</section>
<section class="section" style="padding-top:0">
  <div class="pricing-grid">
    <?php foreach ($plans as $key => $plan): ?>
    <article class="price-card <?= $key === 'd60' ? 'featured' : '' ?>">
      <span><?= strtoupper($key) ?></span>
      <h3><?= e($plan['name']) ?></h3>
      <div class="price"><?= money_inr($plan['price']) ?></div>
      <ul>
        <li><?= (int) $plan['days'] ?> days of paid access</li>
        <li><?= (int) $plan['kits'] ?> job kit<?= $plan['kits'] > 1 ? 's' : '' ?></li>
        <li><?= (int) $plan['credits'] ?> profile-optimization credits</li>
        <li>Fresher price <?= money_inr($plan['fresherPrice']) ?></li>
      </ul>
      <a class="button primary full" href="/checkout">Continue to checkout</a>
    </article>
    <?php endforeach; ?>
  </div>
  <div class="secure-strip" style="margin-top:22px">
    <span>Card data stays with the payment provider</span>
    <span>Access starts after a verified webhook</span>
    <span>Referral commission held 14 days</span>
  </div>
</section>
