<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title ?? 'CVTara') ?></title>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/brand-polish.css">
  <link rel="icon" type="image/png" sizes="64x64" href="/assets/brand/favicon-64.png">
  <link rel="apple-touch-icon" href="/assets/brand/favicon-192.png">
</head>
<body class="setup-body">
  <div class="setup-shell">
    <div class="setup-card" style="max-width:520px;margin:8vh auto">
      <a href="/"><img class="cv-brand-logo" src="/assets/brand/cvtara-wordmark-light.png" alt="CVTara" width="184" height="36"></a>
      <?= $slot ?>
    </div>
  </div>
</body>
</html>
