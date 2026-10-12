<?php
declare(strict_types=1);
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
require dirname(__DIR__) . '/cvtara_app/app/Services/FeedbackBoard.php';

use App\Services\FeedbackBoard;

$hashFile = dirname(__DIR__) . '/cvtara_app/storage/feedback-admin.hash';
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

if (($_POST['action'] ?? '') === 'submit') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $text = trim((string) ($_POST['text'] ?? ''));
    if ($name !== '' && strlen($text) >= 20 && !empty($_POST['consent'])) {
        FeedbackBoard::add($name, trim((string) ($_POST['city'] ?? '')), trim((string) ($_POST['role'] ?? '')), (int) ($_POST['rating'] ?? 5), $text, 'public-form');
    }
    header('Location: /feedback.html?sent=1');
    exit;
}

if (!is_file($hashFile) && ($_POST['action'] ?? '') === 'setup') {
    $pass = (string) ($_POST['password'] ?? '');
    if (strlen($pass) >= 10) {
        if (!is_dir(dirname($hashFile))) {
            mkdir(dirname($hashFile), 0770, true);
        }
        file_put_contents($hashFile, password_hash($pass, PASSWORD_DEFAULT), LOCK_EX);
        $_SESSION['feedback_owner'] = 1;
        header('Location: /owner-reviews.php');
        exit;
    }
}

if (is_file($hashFile) && ($_POST['action'] ?? '') === 'login' && password_verify((string) ($_POST['password'] ?? ''), (string) file_get_contents($hashFile))) {
    session_regenerate_id(true);
    $_SESSION['feedback_owner'] = 1;
    header('Location: /owner-reviews.php');
    exit;
}

$owner = !empty($_SESSION['feedback_owner']);
if ($owner && ($_POST['action'] ?? '') === 'status') {
    FeedbackBoard::setStatus((string) ($_POST['id'] ?? ''), (string) ($_POST['status'] ?? 'pending'));
    header('Location: /owner-reviews.php');
    exit;
}

$items = FeedbackBoard::all()['items'];
?><!doctype html>
<html lang="en-IN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Review feedback — CVTara</title>
  <link rel="stylesheet" href="landing-2026.css">
</head>
<body class="lp">
<main class="lp-wrap lp-legal">
  <p class="lp-kicker">Owner</p>
  <h1>Feedback queue</h1>
  <p>Published notes are written to the homepage. Pending notes stay private.</p>
  <?php if (!is_file($hashFile)): ?>
    <form class="lp-card" method="post">
      <input type="hidden" name="action" value="setup">
      <label>Create the feedback password (10+ characters)<input class="lp-field" type="password" name="password" required minlength="10"></label>
      <button class="lp-btn primary" type="submit">Lock the queue</button>
      <p class="lp-note">Do this before the site is public. The first password becomes the only key.</p>
    </form>
  <?php elseif (!$owner): ?>
    <form class="lp-card" method="post">
      <input type="hidden" name="action" value="login">
      <label>Feedback password<input class="lp-field" type="password" name="password" required></label>
      <button class="lp-btn primary" type="submit">Open queue</button>
    </form>
  <?php else: ?>
    <?php if (!$items): ?><p>No feedback yet. Customers can send it from the workspace or <a href="feedback.html">the public form</a>.</p><?php endif; ?>
    <?php foreach (array_reverse($items) as $item): ?>
      <article class="lp-card">
        <h3><?= $h($item['name']) ?> · <?= (int) $item['rating'] ?>/5 · <?= $h($item['status']) ?></h3>
        <p><?= $h($item['city']) ?> · <?= $h($item['role']) ?> · <?= $h($item['source']) ?></p>
        <p><?= $h($item['text']) ?></p>
        <form method="post" class="lp-cta-row">
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= $h($item['id']) ?>">
          <button class="lp-btn primary" name="status" value="published">Publish on homepage</button>
          <button class="lp-btn secondary" name="status" value="hidden">Hide</button>
          <button class="lp-btn ghost" name="status" value="pending">Return to pending</button>
        </form>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
  <p><a href="home.html">Back to the homepage</a></p>
</main>
</body>
</html>
