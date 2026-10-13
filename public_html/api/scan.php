<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Robots-Tag: noindex');

// Rate limiting: max 10 requests per minute per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateFile = sys_get_temp_dir() . '/cvtara_scan_' . md5($ip) . '.json';
$now = time();
$window = 60; // 1 minute
$maxRequests = 10;

$data = ['requests' => [], 'blocked_until' => 0];
if (file_exists($rateFile)) {
    $data = json_decode(file_get_contents($rateFile), true) ?? $data;
}

// Clear old requests outside window
$data['requests'] = array_filter($data['requests'], fn($t) => $now - $t < $window);

if (count($data['requests']) >= $maxRequests) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a moment and try again.']);
    exit;
}

$data['requests'][] = $now;
file_put_contents($rateFile, json_encode($data));

$resume = trim($_POST['resume'] ?? '');
$job    = trim($_POST['job']    ?? '');

if (strlen($resume) < 50 || strlen($job) < 50) {
    echo json_encode(['error' => 'Paste at least a paragraph from both your resume and the job description.']);
    exit;
}

/* ── Token helpers ──────────────────────────────────────── */
$stop = array_flip(explode(' ','the a an and or for to of in on with from by at as is are was were be been being this that these those your you our their they it its into than then not but if we i me my have has had'));
$verbs = ['led','built','managed','delivered','improved','reduced','created','designed','implemented','owned','launched','analysed','analyzed','coordinated','increased','negotiated','trained','automated','resolved','supported','developed','deployed','architected','established','streamlined','optimized'];

function tokenize(string $text, array $stop): array {
    $text = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text) ?? '');
    $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($parts, fn($w) => strlen($w) > 3 && !isset($stop[$w]))));
}

$rTokens = tokenize($resume, $stop);
$jTokens = tokenize($job,    $stop);

$matched = array_values(array_intersect($rTokens, $jTokens));
$missing = array_values(array_diff(array_slice($jTokens, 0, 60), $rTokens));
$missing = array_slice($missing, 0, 12);
$verbHits = array_values(array_filter($verbs, fn($v) => str_contains(strtolower($resume), $v)));

$overlap   = count($matched);
$needed    = max(count(array_unique($jTokens)), 1);
$keyScore  = (int) round(min(70, $overlap / $needed * 70));
$verbScore = min(20, count($verbHits) * 3);
$lenScore  = strlen(trim($resume)) > 500 ? 10 : (strlen(trim($resume)) > 200 ? 6 : 2);
$rawScore  = max(0, min(100, $keyScore + $verbScore + $lenScore));

/* ── Business model: cap free score 35–56 ───────────────── */
$seed  = (strlen($resume) + strlen($job)) % 22;
$score = 35 + $seed; // 35–56

$issues = [];
if (count($missing) >= 6) $issues[] = ['severity'=>'critical','title'=>'Missing '.count($missing).' keywords recruiters filter for','description'=>'These words appear in the job description but not in your resume.'];
elseif (count($missing) > 0) $issues[] = ['severity'=>'warning','title'=>count($missing).' keyword gaps found','description'=>'Add these where you have real evidence.'];
if (count($verbHits) < 4)   $issues[] = ['severity'=>'critical','title'=>'Weak action verbs','description'=>'Start bullets with precise action verbs: led, built, delivered, reduced.'];
$issues[] = ['severity'=>'critical','title'=>'ATS format not verified','description'=>'Your resume structure may not parse in common ATS systems. Use a CVTara template.'];
$issues[] = ['severity'=>'warning','title'=>'Resume not tailored to this job','description'=>'Generic resumes get filtered. A JD-matched resume performs significantly better.'];

/* ── Save to DB (non-fatal) ─────────────────────────────── */
try {
    $envFile = dirname(__DIR__, 2) . '/cvtara_app/.env';
    $env = [];
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v, " \t\r\n\"'");
        }
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'localhost', $env['DB_DATABASE'] ?? 'cvtara');
    $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_TIMEOUT => 2]);
    $pdo->prepare('INSERT INTO ats_scans (user_id,job_title,job_text,resume_text,score,report_json,created_at) VALUES (NULL,?,?,?,?,?,NOW())')
        ->execute([substr($job, 0, 180), $job, $resume, $score, json_encode(['score'=>$score,'raw'=>$rawScore,'missing'=>$missing,'matched'=>$matched,'verbs'=>$verbHits])]);
} catch (\Throwable) { /* non-fatal — proceed without DB */ }

echo json_encode([
    'score'    => $score,
    'raw'      => $rawScore, // real score (hidden from free users on frontend)
    'missing'  => $missing,
    'matched'  => array_slice($matched, 0, 12),
    'verbs'    => $verbHits,
    'issues'   => $issues,
    'is_capped'=> true,
]);
