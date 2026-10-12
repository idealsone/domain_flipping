<form class="panel panel-pad admin-form" method="post">
  <?= csrf_field() ?>
  <h3>Brand & contact</h3>
  <label>About / footer<textarea class="form-control" name="about"><?= e($brand['about'] ?? '') ?></textarea></label>
  <label>Support email<input class="form-control" name="supportEmail" value="<?= e($legal['supportEmail'] ?? '') ?>"></label>
  <label>Address<textarea class="form-control" name="address"><?= e($legal['address'] ?? '') ?></textarea></label>
  <label>Footer legal line<textarea class="form-control" name="footerText"><?= e($legal['footerText'] ?? '') ?></textarea></label>
  <h3>Policies</h3>
  <label>About page<textarea class="form-control" name="about_page"><?= e($legal['about'] ?? '') ?></textarea></label>
  <label>Privacy<textarea class="form-control" name="privacy"><?= e($legal['privacy'] ?? '') ?></textarea></label>
  <label>Terms<textarea class="form-control" name="terms"><?= e($legal['terms'] ?? '') ?></textarea></label>
  <label>Refunds<textarea class="form-control" name="refund"><?= e($legal['refund'] ?? '') ?></textarea></label>
  <h3>Search</h3>
  <label>Public site URL<input class="form-control" name="siteUrl" value="<?= e($seo['siteUrl'] ?? '') ?>"></label>
  <label class="switch-line"><span>XML sitemap</span><input type="checkbox" name="sitemapEnabled" <?= !empty($seo['sitemapEnabled'])?'checked':'' ?>></label>
  <label class="switch-line"><span>Allow search indexing</span><input type="checkbox" name="searchIndexing" <?= !empty($seo['searchIndexing'])?'checked':'' ?>></label>
  <h3>Referrals</h3>
  <label class="switch-line"><span>Enable referral programme</span><input type="checkbox" name="growthEnabled" <?= !empty($growth['enabled'])?'checked':'' ?>></label>
  <label>Commission %<input class="form-control" name="rate" type="number" step="0.1" value="<?= e((string) (($growth['rateBps'] ?? 2000)/100)) ?>"></label>
  <label>Hold days<input class="form-control" name="holdDays" type="number" value="<?= (int) ($growth['holdDays'] ?? 14) ?>"></label>
  <h3>Cashfree payments</h3>
  <p>Card details stay on Cashfree. CVTara stores only an order reference. Paste keys from the Cashfree dashboard, then set the webhook URL to <code><?= e(url('/webhooks/cashfree')) ?></code>.</p>
  <label>Environment
    <select class="form-control" name="cf_env">
      <option value="sandbox" <?= (($payments['env'] ?? 'sandbox') !== 'production') ? 'selected' : '' ?>>Sandbox</option>
      <option value="production" <?= (($payments['env'] ?? '') === 'production') ? 'selected' : '' ?>>Production</option>
    </select>
  </label>
  <label>PG App ID<input class="form-control" name="cf_app_id" value="<?= e($payments['appId'] ?? '') ?>" autocomplete="off"></label>
  <label>PG Secret Key<input class="form-control" type="password" name="cf_secret" placeholder="<?= !empty($payments['secretKey']) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></label>
  <label>Webhook secret<input class="form-control" type="password" name="cf_webhook" placeholder="<?= !empty($payments['webhookSecret']) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></label>
  <label>Payout App ID (optional)<input class="form-control" name="cf_payout_app_id" value="<?= e($payments['payoutAppId'] ?? '') ?>" autocomplete="off"></label>
  <label>Payout secret (optional)<input class="form-control" type="password" name="cf_payout_secret" placeholder="<?= !empty($payments['payoutSecret']) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></label>
  <h3>Writing assistance keys</h3>
  <p>Used only inside the workspace optimizer. Public pages never name a provider. Leave blank to keep a local rewrite.</p>
  <label>OpenAI key<input class="form-control" type="password" name="openai_key" placeholder="<?= !empty($writing['openaiKey']) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></label>
  <label>Anthropic key<input class="form-control" type="password" name="anthropic_key" placeholder="<?= !empty($writing['anthropicKey']) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></label>
  <label>xAI key<input class="form-control" type="password" name="xai_key" placeholder="<?= !empty($writing['xaiKey']) ? 'Saved — leave blank to keep' : '' ?>" autocomplete="new-password"></label>
  <button class="button primary">Save settings</button>
</form>
