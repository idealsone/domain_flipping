<?php
declare(strict_types=1);

use App\Services\AbandonedCartService;
use App\Services\OutreachService;
use App\Support\DB;
use App\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$router = require __DIR__ . '/bootstrap.php';
$jobs = DB::fetchAll("SELECT * FROM email_jobs WHERE status = 'queued' AND due_at <= NOW() LIMIT 30");
foreach ($jobs as $job) {
    $ok = \App\Support\Mailer::send($job['recipient'], $job['subject'], $job['body'], \App\Support\Mailer::transactional());
    DB::update('email_jobs', [
        'status' => $ok ? 'sent' : 'failed',
        'sent_at' => $ok ? now() : null,
        'error' => $ok ? null : 'Provider rejected the message',
    ], 'id = ?', [$job['id']]);
}

DB::query("UPDATE wallet_entries SET status = 'available', type = 'credit' WHERE status = 'pending' AND hold_until IS NOT NULL AND hold_until <= NOW()");

$campaigns = (new OutreachService())->process();
$carts = (new AbandonedCartService())->process();
Logger::info('Cron finished', ['queued_mail' => count($jobs), 'campaigns' => $campaigns, 'carts' => $carts]);
echo "ok campaigns={$campaigns} carts={$carts}\n";
