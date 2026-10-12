<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'Owner') ?> · CVTara</title>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/brand-polish.css">
  <link rel="stylesheet" href="/owner-console.css">
</head>
<body class="admin-body">
<div class="admin-shell">
  <aside class="side-panel admin-side">
    <a class="brand" href="/admin"><img class="cv-brand-logo" src="/assets/brand/cvtara-wordmark-dark.png" alt="CVTara" width="154" height="30"></a>
    <nav class="side-nav">
      <a href="/admin" class="<?= ($title ?? '') === 'Owner overview' ? 'active' : '' ?>"><i>▣</i> Overview</a>
      <a href="/admin/users" class="<?= ($title ?? '') === 'Users' ? 'active' : '' ?>"><i>●</i> Users</a>
      <a href="/admin/operations" class="<?= ($title ?? '') === 'Operations' ? 'active' : '' ?>"><i>⚙</i> Operations</a>
      <a href="/admin/mailboxes" class="<?= ($title ?? '') === 'Mailboxes' ? 'active' : '' ?>"><i>✉</i> Mailboxes</a>
      <a href="/admin/leads" class="<?= ($title ?? '') === 'Leads' ? 'active' : '' ?>"><i>↗</i> Leads</a>
      <a href="/admin/reviews" class="<?= ($title ?? '') === 'Feedback' ? 'active' : '' ?>"><i>★</i> Feedback</a>
      <a href="/admin/settings" class="<?= ($title ?? '') === 'Settings' ? 'active' : '' ?>"><i>☰</i> Settings</a>
    </nav>
    <div class="side-plan"><span>Signed in as owner</span><b><?= e($user['name'] ?? '') ?></b></div>
  </aside>
  <div class="admin-main">
    <header class="admin-top">
      <h1><?= e($title ?? 'Owner') ?></h1>
      <div class="top-actions">
        <a class="button secondary" href="/" target="_blank" rel="noopener">Preview site</a>
        <form method="post" action="/logout"><?= csrf_field() ?><button class="button ghost">Sign out</button></form>
      </div>
    </header>
    <div class="admin-content">
      <?php include __DIR__ . '/../partials/flash.php'; ?>
      <?= $slot ?>
    </div>
  </div>
</div>
</body>
</html>
