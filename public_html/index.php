<?php
declare(strict_types=1);

$root = is_dir(__DIR__ . '/../cvtara_app') ? realpath(__DIR__ . '/../cvtara_app') : dirname(__DIR__);
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$marketing = [
    '/' => 'home.html',
    '/pricing' => 'pricing.html',
    '/templates' => 'templates.html',
    '/tools' => 'tools.html',
    '/guides' => 'guides.html',
    '/about' => 'about.html',
    '/contact' => 'contact.html',
    '/register' => 'register.html',
    '/login' => 'login.html',
    '/privacy-policy' => 'privacy-policy.html',
    '/terms' => 'terms.html',
    '/terms-and-conditions' => 'terms.html',
    '/refund-policy' => 'refund-policy.html',
    '/blog' => 'blog.html',
    '/feedback' => 'feedback.html',
    '/how-to-write-a-resume' => 'how-to-write-a-resume.html',
    '/resume-format' => 'resume-format.html',
    '/ats-friendly-resume' => 'ats-friendly-resume.html',
    '/resume-for-freshers' => 'resume-for-freshers.html',
    '/resume-score' => 'resume-score.html',
    '/in-hand-salary' => 'in-hand-salary.html',
    '/how-to-negotiate-salary' => 'how-to-negotiate-salary.html',
    '/cvtara-vs-other-resume-builders' => 'cvtara-vs-other-resume-builders.html',
];
$installed = is_file($root . '/.env') && is_file($root . '/storage/installed.lock');
if (!$installed) {
    if ($path === '/api/site') {
        $reviews = ['published' => []];
        $reviewFile = __DIR__ . '/reviews.json';
        if (is_file($reviewFile)) {
            $decoded = json_decode((string) file_get_contents($reviewFile), true);
            if (is_array($decoded) && isset($decoded['published']) && is_array($decoded['published'])) {
                $reviews = ['published' => $decoded['published']];
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'connected' => false,
            'plans' => [
                'fresher' => ['₹199', '₹349', '₹499'],
                'professional' => ['₹299', '₹499', '₹699'],
            ],
            'reviews' => $reviews,
            'activity' => [],
            'counts' => ['members' => 0, 'scans' => 0, 'publishedReviews' => count($reviews['published'])],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($path === '/api/events') {
        http_response_code(204);
        exit;
    }
    if ($path === '/sitemap.xml') {
        $origin = 'https://' . preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_unique(array_merge(['/'], array_keys($marketing))) as $url) {
            echo '<url><loc>' . htmlspecialchars($origin . $url, ENT_XML1) . '</loc></url>';
        }
        echo '</urlset>';
        exit;
    }
    $file = $marketing[$path] ?? null;
    if ($file && is_file(__DIR__ . '/' . $file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/' . $file);
        exit;
    }
    header('Location: /');
    exit;
}

$router = require $root . '/bootstrap.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper((string) $_POST['_method']);
}
$result = $router->dispatch($method, $_SERVER['REQUEST_URI'] ?? '/');
if ($result === null) {
    http_response_code(404);
    echo \App\Support\View::render('pages/not-found', ['title' => 'Not found']);
}
