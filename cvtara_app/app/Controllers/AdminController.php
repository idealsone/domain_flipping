<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AbandonedCartService;
use App\Services\FeedbackBoard;
use App\Services\LeadImportService;
use App\Services\OutreachService;
use App\Services\PaymentService;
use App\Support\Auth;
use App\Support\Cache;
use App\Support\Crypto;
use App\Support\DB;
use App\Support\Logger;
use App\Support\Response;
use App\Support\Settings;
use App\Support\View;

final class AdminController
{
    public function overview(): never
    {
        $stats = [
            'users'         => (int) DB::value('SELECT COUNT(*) FROM users'),
            'paid'          => (int) DB::value('SELECT COUNT(*) FROM users WHERE is_premium = 1 AND (plan_expires_at IS NULL OR plan_expires_at > NOW())'),
            'leads'         => (int) DB::value('SELECT COUNT(*) FROM leads'),
            'scans'         => (int) DB::value('SELECT COUNT(*) FROM ats_scans'),
            'revenue'       => (int) DB::value("SELECT COALESCE(SUM(amount_paise),0) FROM purchases WHERE status = 'paid'"),
            'scans_today'   => (int) DB::value("SELECT COUNT(*) FROM ats_scans WHERE DATE(created_at) = CURDATE()"),
            'revenue_today' => (int) DB::value("SELECT COALESCE(SUM(amount_paise),0) FROM purchases WHERE status = 'paid' AND DATE(created_at) = CURDATE()"),
        ];
        $purchases = DB::fetchAll('SELECT p.*, u.email FROM purchases p LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 20');
        Response::html(View::render('admin/overview', ['title' => 'Owner overview', 'stats' => $stats, 'purchases' => $purchases], 'layouts/admin'));
    }

    public function users(): never
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT id,name,email,experience_tier,is_premium,plan,plan_expires_at,created_at FROM users ORDER BY id DESC LIMIT 300';
        $params = [];
        if ($q !== '') {
            $sql = 'SELECT id,name,email,experience_tier,is_premium,plan,plan_expires_at,created_at FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 300';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        Response::html(View::render('admin/users', ['title' => 'Users', 'users' => DB::fetchAll($sql, $params), 'q' => $q], 'layouts/admin'));
    }

    public function markPaid(string $id): never
    {
        $purchase = DB::fetch('SELECT * FROM purchases WHERE id = ?', [$id]);
        if (!$purchase) {
            flash('error', 'Purchase not found.');
            Response::redirect('/admin/users');
        }
        DB::update('purchases', ['status' => 'paid', 'payment_reference' => 'manual-' . $id, 'is_live' => 0], 'id = ?', [$id]);
        (new PaymentService())->activate((int) $purchase['user_id'], $purchase['plan'], (int) $purchase['credits_addon'], 'manual-' . $id, 'manual', false);
        flash('success', 'Access activated for acceptance testing.');
        Response::redirect('/admin');
    }

    public function operations(): never
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $level = trim((string) ($_GET['level'] ?? ''));
        Response::html(View::render('admin/operations', [
            'title' => 'Operations',
            'logs' => Logger::tail(200, $query, $level),
            'query' => $query,
            'level' => $level,
            'maintenance' => Settings::get('maintenance', ['enabled' => false, 'title' => '', 'message' => '', 'whitelist' => []]),
            'ip' => client_ip(),
        ], 'layouts/admin'));
    }

    public function saveMaintenance(): never
    {
        Settings::put('maintenance', [
            'enabled' => !empty($_POST['enabled']),
            'title' => trim((string) ($_POST['title'] ?? 'We will be right back')),
            'message' => trim((string) ($_POST['message'] ?? 'CVTara is being updated.')),
            'whitelist' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['whitelist'] ?? ''))))),
        ]);
        flash('success', 'Maintenance settings saved. You can switch the public site off and on from this page.');
        Response::redirect('/admin/operations');
    }

    public function clearLogs(): never
    {
        Logger::clear();
        flash('success', 'Error log cleared.');
        Response::redirect('/admin/operations');
    }

    public function clearCache(): never
    {
        $n = Cache::flush();
        flash('success', $n . ' cached files removed.');
        Response::redirect('/admin/operations');
    }

    public function mailboxes(): never
    {
        Response::html(View::render('admin/mailboxes', [
            'title' => 'Mailboxes',
            'boxes' => DB::fetchAll('SELECT id,label,purpose,host,port,username,from_name,from_email,enabled,last_used_at FROM mailboxes ORDER BY purpose,id'),
        ], 'layouts/admin'));
    }

    public function mailboxSave(): never
    {
        $data = [
            'label' => trim((string) ($_POST['label'] ?? 'Mailbox')),
            'purpose' => ($_POST['purpose'] ?? '') === 'transactional' ? 'transactional' : 'marketing',
            'host' => trim((string) ($_POST['host'] ?? '')),
            'port' => (int) ($_POST['port'] ?? 587),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'encryption' => in_array($_POST['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['encryption'] : 'tls',
            'from_name' => trim((string) ($_POST['from_name'] ?? 'CVTara')),
            'from_email' => trim((string) ($_POST['from_email'] ?? '')),
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
        ];
        $id = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if ($id) {
            if ($password !== '') {
                $data['password_enc'] = Crypto::encrypt($password);
            }
            DB::update('mailboxes', $data, 'id = ?', [$id]);
        } else {
            if ($data['host'] === '' || $data['from_email'] === '' || $password === '') {
                flash('error', 'Host, from email and password are required for a new mailbox.');
                Response::redirect('/admin/mailboxes');
            }
            $data['password_enc'] = Crypto::encrypt($password);
            $data['created_at'] = now();
            DB::insert('mailboxes', $data);
        }
        flash('success', 'Mailbox saved. Transactional mail uses the main domain box; marketing rotates through enabled marketing boxes.');
        Response::redirect('/admin/mailboxes');
    }

    public function leads(): never
    {
        Response::html(View::render('admin/leads', [
            'title' => 'Leads',
            'leads' => DB::fetchAll('SELECT * FROM leads ORDER BY id DESC LIMIT 200'),
            'sources' => LeadImportService::ALLOWED_SOURCES,
        ], 'layouts/admin'));
    }

    public function leadImport(): never
    {
        $source = (string) ($_POST['source'] ?? 'csv-import');
        $note = trim((string) ($_POST['consent_note'] ?? 'Owner-confirmed consent'));
        if ($note === '') {
            flash('error', 'Record how these people consented before importing.');
            Response::redirect('/admin/leads');
        }
        $csv = (string) ($_POST['csv'] ?? '');
        if (!empty($_FILES['file']['tmp_name'])) {
            $csv = (string) file_get_contents($_FILES['file']['tmp_name']);
        }
        $result = (new LeadImportService())->importCsv($csv, $source, $note);
        flash('success', $result['created'] . ' leads added, ' . $result['skipped'] . ' skipped. LinkedIn scraping is not supported; only consented exports or opt-in forms.');
        Response::redirect('/admin/leads');
    }

    public function leadOptIn(): never
    {
        try {
            (new LeadImportService())->add((string) ($_POST['email'] ?? ''), trim((string) ($_POST['name'] ?? '')) ?: null, 'opt-in', 'Manual owner entry');
            flash('success', 'Lead added.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        Response::redirect('/admin/leads');
    }

    public function runOutreach(): never
    {
        $n = (new OutreachService())->process();
        flash('success', $n . ' consented campaign messages accepted by the provider.');
        Response::redirect('/admin/leads');
    }

    public function runAbandoned(): never
    {
        $n = (new AbandonedCartService())->process();
        flash('success', $n . ' abandoned-scan follow-ups sent.');
        Response::redirect('/admin/leads');
    }

    public function settings(): never
    {
        Response::html(View::render('admin/settings', [
            'title' => 'Settings',
            'brand' => Settings::get('brand', []),
            'growth' => Settings::get('growth', []),
            'legal' => Settings::get('legal', []),
            'seo' => Settings::get('seo', []),
            'payments' => Settings::get('payments', []),
            'writing' => Settings::get('writing', []),
        ], 'layouts/admin'));
    }

    public function settingsSave(): never
    {
        Settings::put('brand', ['about' => trim((string) ($_POST['about'] ?? ''))]);
        Settings::put('legal', [
            'supportEmail' => trim((string) ($_POST['supportEmail'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'footerText' => trim((string) ($_POST['footerText'] ?? '')),
            'privacy' => trim((string) ($_POST['privacy'] ?? '')),
            'terms' => trim((string) ($_POST['terms'] ?? '')),
            'refund' => trim((string) ($_POST['refund'] ?? '')),
            'about' => trim((string) ($_POST['about_page'] ?? '')),
        ]);
        Settings::put('seo', [
            'sitemapEnabled' => !empty($_POST['sitemapEnabled']),
            'searchIndexing' => !empty($_POST['searchIndexing']),
            'siteUrl' => rtrim(trim((string) ($_POST['siteUrl'] ?? '')), '/'),
        ]);
        Settings::put('growth', [
            'enabled' => !empty($_POST['growthEnabled']),
            'rateBps' => (int) round(((float) ($_POST['rate'] ?? 20)) * 100),
            'holdDays' => (int) ($_POST['holdDays'] ?? 14),
        ]);
        $keep = static function (string $posted, string $existing): string {
            $posted = trim($posted);
            return $posted === '' ? $existing : $posted;
        };
        $payments = Settings::get('payments', []);
        $writing = Settings::get('writing', []);
        Settings::put('payments', [
            'appId' => trim((string) ($_POST['cf_app_id'] ?? '')),
            'secretKey' => $keep((string) ($_POST['cf_secret'] ?? ''), (string) ($payments['secretKey'] ?? '')),
            'webhookSecret' => $keep((string) ($_POST['cf_webhook'] ?? ''), (string) ($payments['webhookSecret'] ?? '')),
            'env' => (($_POST['cf_env'] ?? '') === 'production') ? 'production' : 'sandbox',
            'payoutAppId' => trim((string) ($_POST['cf_payout_app_id'] ?? '')),
            'payoutSecret' => $keep((string) ($_POST['cf_payout_secret'] ?? ''), (string) ($payments['payoutSecret'] ?? '')),
        ]);
        Settings::put('writing', [
            'openaiKey' => $keep((string) ($_POST['openai_key'] ?? ''), (string) ($writing['openaiKey'] ?? '')),
            'anthropicKey' => $keep((string) ($_POST['anthropic_key'] ?? ''), (string) ($writing['anthropicKey'] ?? '')),
            'xaiKey' => $keep((string) ($_POST['xai_key'] ?? ''), (string) ($writing['xaiKey'] ?? '')),
        ]);
        flash('success', 'Settings saved.');
        Response::redirect('/admin/settings');
    }

    public function reviews(): never
    {
        Response::html(View::render('admin/reviews', [
            'title' => 'Feedback',
            'items' => array_reverse(FeedbackBoard::all()['items']),
        ], 'layouts/admin'));
    }

    public function reviewStatus(): never
    {
        FeedbackBoard::setStatus((string) ($_POST['id'] ?? ''), (string) ($_POST['status'] ?? 'pending'));
        flash('success', 'Homepage feedback updated.');
        Response::redirect('/admin/reviews');
    }
}
