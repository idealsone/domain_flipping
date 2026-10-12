<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'Workspace') ?> · CVTara</title>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/brand-polish.css">
  <link rel="stylesheet" href="/resume-studio.css">
</head>
<body class="workspace-body">
<div class="workspace-shell">
  <aside class="side-panel">
    <a class="brand" href="/workspace"><img class="cv-brand-logo" src="/assets/brand/cvtara-wordmark-dark.png" alt="CVTara" width="154" height="30"></a>
    <nav class="side-nav">
      <a href="/workspace" class="<?= ($title ?? '') === 'Workspace' ? 'active' : '' ?>"><i>⌂</i> Overview</a>
      <a href="/workspace/resume" class="<?= ($title ?? '') === 'Resume studio' ? 'active' : '' ?>"><i>R</i> Resume studio</a>
      <a href="/workspace/match" class="<?= ($title ?? '') === 'Compliance match' ? 'active' : '' ?>"><i>%</i> Compliance match</a>
      <a href="/workspace/optimize" class="<?= ($title ?? '') === 'Profile optimization' ? 'active' : '' ?>"><i>✎</i> Optimize</a>
      <a href="/workspace/applications" class="<?= ($title ?? '') === 'Applications' ? 'active' : '' ?>"><i>▣</i> Applications</a>
      <a href="/checkout" class="<?= ($title ?? '') === 'Checkout' ? 'active' : '' ?>"><i>₹</i> Plans</a>
      <a href="/wallet" class="<?= ($title ?? '') === 'Referral wallet' ? 'active' : '' ?>"><i>↗</i> Wallet</a>
      <a href="/account" class="<?= ($title ?? '') === 'Account' ? 'active' : '' ?>"><i>●</i> Account</a>
    </nav>
    <div class="side-plan">
      <span>Access</span>
      <b><?= is_premium($user) ? e($user['plan'] ?: 'Active') : 'Free preview' ?></b>
      <small><?= is_premium($user) && !empty($user['plan_expires_at']) ? 'Until ' . e($user['plan_expires_at']) : 'Upgrade to remove limits' ?></small>
    </div>
  </aside>
  <div class="workspace-main">
    <header class="workspace-top">
      <h1><?= e($title ?? 'Workspace') ?></h1>
      <div class="top-actions">
        <a class="button secondary" href="/">Public site</a>
        <form method="post" action="/logout"><?= csrf_field() ?><button class="button ghost">Sign out</button></form>
      </div>
    </header>
    <div class="workspace-content">
      <?php include __DIR__ . '/../partials/flash.php'; ?>
      <?= $slot ?>
    </div>
  </div>
</div>
</body>
</html>
