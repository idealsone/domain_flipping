<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'CVTara') ?></title>
  <meta name="description" content="<?= e($description ?? 'Structured resumes, compliance scans and application tracking.') ?>">
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/brand-polish.css">
  <link rel="stylesheet" href="/home-refresh.css">
  <link rel="icon" type="image/png" sizes="64x64" href="/assets/brand/favicon-64.png">
  <link rel="apple-touch-icon" href="/assets/brand/favicon-192.png">
  <meta name="theme-color" content="#1557d2">
</head>
<body>
<header class="site-header">
  <div class="nav-shell">
    <a class="brand" href="/"><img class="cv-brand-logo" src="/assets/brand/cvtara-wordmark-light.png" alt="CVTara" width="184" height="36"></a>
    <nav class="main-nav">
      <a href="/scan">Compliance scan</a>
      <a href="/tools">Tools</a>
      <a href="/guides">Guides</a>
      <a href="/pricing">Pricing</a>
    </nav>
    <div class="nav-actions">
      <?php if ($user): ?>
        <a class="button secondary" href="<?= !empty($user['is_admin']) ? '/admin' : '/workspace' ?>">Dashboard</a>
      <?php else: ?>
        <a class="button secondary sign-in-link" href="/login">Sign in</a>
        <a class="button primary desktop-cta" href="/register">Create workspace</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main>
  <?= $slot ?>
</main>
<footer class="site-footer">
  <div class="footer-shell">
    <div class="footer-main">
      <div>
        <img class="cv-brand-logo" src="/assets/brand/cvtara-lockup-dark.png" alt="CVTara" width="227" height="64">
        <p><?= e($settings['brand']['about'] ?? 'Clear resumes. Focused applications.') ?></p>
      </div>
      <nav><b>Product</b><a href="/scan">Compliance scan</a><a href="/pricing">Pricing</a><a href="/workspace">Workspace</a></nav>
      <nav><b>Resources</b><a href="/tools">Tools</a><a href="/guides">Guides</a><a href="/contact">Contact</a></nav>
      <nav><b>Legal</b><a href="/privacy-policy">Privacy</a><a href="/terms-and-conditions">Terms</a><a href="/refund-policy">Refunds</a></nav>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> CVTara. Overlap scores are not hiring guarantees.</span>
      <div class="security-marks"><span>Cards stay on the payment provider</span><span>Passwords hashed</span></div>
    </div>
  </div>
</footer>
</body>
</html>
