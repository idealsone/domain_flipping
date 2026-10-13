<?php
declare(strict_types=1);

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$root = is_dir(__DIR__ . '/../cvtara_app') ? realpath(__DIR__ . '/../cvtara_app') : dirname(__DIR__);
$lockFile = $root . '/storage/installed.lock';
if (is_file($lockFile)) {
    http_response_code(403);
    exit('Installation is locked. Sign in at /login.');
}

require $root . '/app/Support/helpers.php';
require $root . '/app/Support/Installer.php';

$setupHash = trim((string) @file_get_contents(__DIR__ . '/.install-key-hash'));
$setupKey = trim((string) @file_get_contents($root . '/.setup-key'));
$errors = [];
$success = false;
$_SESSION['install_csrf'] ??= bin2hex(random_bytes(24));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['install_csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Refresh the installer and try again.');
        }
        $posted = trim((string) ($_POST['setup_key'] ?? ''));
        $okKey = ($setupHash && hash_equals($setupHash, hash('sha256', $posted)))
            || ($setupKey && hash_equals($setupKey, $posted));
        if (!$okKey) {
            throw new RuntimeException('Use the installation key from INSTALL_KEY.txt.');
        }
        if (PHP_VERSION_ID < 80300) {
            throw new RuntimeException('Choose PHP 8.3 or newer in hPanel.');
        }
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new RuntimeException('Enable PHP extension: ' . $ext);
            }
        }
        $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = (string) ($parts['scheme'] ?? '');
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $local))) {
            throw new RuntimeException('Enter your HTTPS domain without a path. Enable SSL first. http://localhost is allowed only for local setup.');
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12) {
            throw new RuntimeException('Use a valid owner email, name and a password of at least 12 characters.');
        }
        $host = trim((string) ($_POST['db_host'] ?? 'localhost'));
        $db = trim((string) ($_POST['db_name'] ?? ''));
        $user = trim((string) ($_POST['db_user'] ?? ''));
        $pass = (string) ($_POST['db_password'] ?? '');

        // Validate to prevent DSN injection
        if (!preg_match('/^[a-zA-Z0-9._:\-]+$/', $host)) {
            $errors[] = 'Invalid database host format.';
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $db)) {
            $errors[] = 'Invalid database name format.';
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $user)) {
            $errors[] = 'Invalid database username format.';
        }
        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if ($existing && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            throw new RuntimeException('This database already contains accounts. Use an empty database.');
        }
        foreach (['/storage', '/storage/logs', '/storage/cache', '/storage/framework'] as $dir) {
            if (!is_dir($root . $dir)) {
                mkdir($root . $dir, 0770, true);
            }
        }
        $quote = static function (string $s): string {
            if (preg_match('/[\r\n\x00]/', $s)) {
                throw new RuntimeException('Configuration cannot contain line breaks.');
            }
            return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s) . '"';
        };
        $key = 'base64:' . base64_encode(random_bytes(32));
        $env = [
            'APP_NAME' => 'CVTara',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $url,
            'APP_KEY' => $key,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => '3306',
            'DB_DATABASE' => $db,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $pass,
            'MAIL_MAILER' => 'smtp',
            'LOG_LEVEL' => 'error',
            'CASHFREE_ENV' => 'sandbox',
            'CASHFREE_APP_ID' => '',
            'CASHFREE_SECRET_KEY' => '',
            'CASHFREE_WEBHOOK_SECRET' => '',
            'CASHFREE_PAYOUT_APP_ID' => '',
            'CASHFREE_PAYOUT_SECRET' => '',
            'OPENAI_API_KEY' => '',
            'ANTHROPIC_API_KEY' => '',
            'XAI_API_KEY' => '',
        ];
        $text = '';
        foreach ($env as $k => $v) {
            $text .= $k . '=' . $quote((string) $v) . "\n";
        }
        if (file_put_contents($root . '/.env', $text, LOCK_EX) === false) {
            throw new RuntimeException('App directory is not writable.');
        }
        @chmod($root . '/.env', 0600);
        App\Support\Installer::migrate($pdo);
        App\Support\Installer::seed($pdo, ['name' => $name, 'email' => $email, 'password' => $password], $url);
        file_put_contents($lockFile, date(DATE_ATOM));
        @unlink($root . '/.setup-key');
        $success = true;
    } catch (Throwable $e) {
        $errors[] = $e instanceof PDOException
            ? 'Could not connect to the database. Check the exact hPanel credentials.'
            : $e->getMessage();
    }
}

function h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Install CVTara</title>
  <link rel="stylesheet" href="/styles.css">
  <link rel="stylesheet" href="/brand-polish.css">
</head>
<body class="setup-body">
<main class="setup-card" style="max-width:640px;margin:6vh auto">
  <img class="cv-brand-logo" src="/assets/brand/cvtara-wordmark-light.png" width="220" height="28" alt="CVTara">
  <h1>Install CVTara</h1>
  <?php if ($success): ?>
    <h2>Installation complete</h2>
    <p>Delete <code>public_html/install.php</code> and the local <code>INSTALL_KEY.txt</code>. Sign in, then add transactional and marketing mailboxes, payment keys and your cron job.</p>
    <a class="button primary" href="/login">Sign in as owner</a>
  <?php else: ?>
    <p>This setup creates the owner account, database tables and locks itself. Composer and npm are not required.</p>
    <?php foreach ($errors as $err): ?><p class="error" role="alert" style="color:#ad3a4b"><?= h($err) ?></p><?php endforeach; ?>
    <form class="admin-form" method="post">
      <input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>">
      <?php
      $fields = [
          'setup_key' => 'Installation key',
          'url' => 'HTTPS domain (https://cvtara.com)',
          'db_host' => 'Database host',
          'db_name' => 'Database name',
          'db_user' => 'Database username',
          'db_password' => 'Database password',
          'name' => 'Owner full name',
          'email' => 'Owner email',
          'password' => 'Owner password (12+ characters)',
      ];
      foreach ($fields as $key => $label):
          $type = str_contains($key, 'password') || $key === 'setup_key' ? 'password' : ($key === 'email' ? 'email' : 'text');
          $value = $key === 'db_host' ? 'localhost' : ($key === 'url' ? 'https://' . preg_replace('/[^a-zA-Z0-9.-]/', '', $_SERVER['HTTP_HOST'] ?? '') : '');
      ?>
      <label><?= h($label) ?><input class="form-control" name="<?= $key ?>" type="<?= $type ?>" required value="<?= h($value) ?>" autocomplete="off"></label>
      <?php endforeach; ?>
      <button class="button primary">Install and create owner</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
