<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

/**
 * Fetch a public job-description page the visitor already has a link to,
 * and return readable text. Blocks private/local hosts.
 */
$url = trim((string) ($_POST['url'] ?? $_GET['url'] ?? ''));
if ($url === '' || strlen($url) > 2000) {
    echo json_encode(['error' => 'Paste a job listing URL.']);
    exit;
}

$parts = parse_url($url);
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
$host   = strtolower((string) ($parts['host'] ?? ''));

if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    echo json_encode(['error' => 'Use a full http or https job link.']);
    exit;
}

$blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
if (in_array($host, $blockedHosts, true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
    echo json_encode(['error' => 'That link cannot be fetched. Paste the job text instead.']);
    exit;
}

$ip = gethostbyname($host);
if ($ip && $ip !== $host && (
    filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
)) {
    echo json_encode(['error' => 'That link cannot be fetched. Paste the job text instead.']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CVTaraJobFetch/1.0)',
    CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
    CURLOPT_SSL_VERIFYPEER => true,
]);
if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}
$html = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($html === false || $code >= 400 || strlen((string) $html) < 40) {
    echo json_encode(['error' => 'Could not read that page. Paste the job description instead.']);
    exit;
}

$html = (string) $html;
$html = preg_replace('#<(script|style|noscript|svg|nav|footer|header)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
$text = trim($text);
if (strlen($text) > 12000) {
    $text = substr($text, 0, 12000);
}

if (strlen($text) < 80) {
    echo json_encode(['error' => 'That page did not contain enough job text. Paste the description instead.']);
    exit;
}

echo json_encode(['ok' => true, 'text' => $text]);
