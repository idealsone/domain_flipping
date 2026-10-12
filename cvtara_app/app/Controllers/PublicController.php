<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AtsScannerService;
use App\Services\ContentCatalog;
use App\Services\LeadImportService;
use App\Services\SiteFeed;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\DB;
use App\Support\Mailer;
use App\Support\Response;
use App\Support\Settings;
use App\Support\View;

final class PublicController
{
    public function home(): never
    {
        $this->marketingPage('home.html');
    }

    public function homeFile(string $file): never
    {
        $this->marketingPage($file);
    }

    public function pricing(): never
    {
        $this->marketingPage('pricing.html');
    }

    public function templates(): never
    {
        $this->marketingPage('templates.html');
    }

    public function tools(): never
    {
        $this->marketingPage('tools.html');
    }

    public function guides(): never
    {
        $this->marketingPage('guides.html');
    }

    public function tool(string $slug): never
    {
        $item = ContentCatalog::find('Calculator', $slug);
        if (!$item) {
            Response::html(View::render('pages/not-found', ['title' => 'Not found'], 'layouts/public'), 404);
        }
        Response::html(View::render('pages/article', [
            'title' => $item['title'],
            'item' => $item,
            'related' => ContentCatalog::related($item),
        ]));
    }

    public function guide(string $a, string $b): never
    {
        $item = ContentCatalog::find('Guide', $a . '/' . $b);
        if (!$item) {
            Response::html(View::render('pages/not-found', ['title' => 'Not found']), 404);
        }
        Response::html(View::render('pages/article', [
            'title' => $item['title'],
            'item' => $item,
            'related' => ContentCatalog::related($item),
        ]));
    }

    public function legal(string $slug): never
    {
        $legal = Settings::cached('legal', []);
        $map = [
            'privacy-policy' => ['Privacy policy', $legal['privacy'] ?? $this->privacyText()],
            'terms-and-conditions' => ['Terms and conditions', $legal['terms'] ?? $this->termsText()],
            'refund-policy' => ['Refund policy', $legal['refund'] ?? $this->refundText()],
            'about' => ['About', $legal['about'] ?? 'CVTara is a career-document workspace for structured resumes, compliance scans and application tracking.'],
        ];
        if (!isset($map[$slug])) {
            Response::html(View::render('pages/not-found', ['title' => 'Not found']), 404);
        }
        Response::html(View::render('pages/legal', [
            'title' => $map[$slug][0],
            'body' => $map[$slug][1],
        ]));
    }

    public function contact(): never
    {
        Response::html(View::render('pages/contact', [
            'title' => 'Contact',
            'legal' => Settings::cached('legal', []),
        ]));
    }

    public function contactSubmit(): never
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($message) < 10) {
            flash('error', 'Use a valid email and a message of at least 10 characters.');
            Response::redirect('/contact');
        }
        $owner = Settings::cached('legal', [])['supportEmail'] ?? null;
        if ($owner) {
            Mailer::send($owner, 'CVTara contact form', Mailer::wrap('Contact form', '<p>' . e($name) . ' · ' . e($email) . '</p><p>' . nl2br(e($message)) . '</p>'), Mailer::transactional());
        }
        if (!empty($_POST['updates'])) {
            (new LeadImportService())->add($email, $name, 'opt-in', 'Contact form opt-in');
        }
        flash('success', 'Message received. We will reply from the support mailbox.');
        Response::redirect('/contact');
    }

    public function scan(): never
    {
        Response::html(View::render('pages/scan', ['title' => 'Compliance scan', 'report' => null]));
    }

    public function scanSubmit(): never
    {
        $resume = trim((string) ($_POST['resume'] ?? ''));
        $job = trim((string) ($_POST['job'] ?? ''));
        if (strlen($resume) < 40 || strlen($job) < 40) {
            flash('error', 'Paste both the resume and the job description.');
            Response::redirect('/scan');
        }
        $user = Auth::check() ? Auth::user() : null;
        $isPremium = isset($user) && !empty($user['is_premium']);
        $report = (new AtsScannerService())->scan($resume, $job, $isPremium);
        DB::insert('ats_scans', [
            'user_id' => Auth::id(),
            'job_title' => trim((string) ($_POST['job_title'] ?? '')) ?: null,
            'job_text' => $job,
            'resume_text' => $resume,
            'score' => $report['score'],
            'report_json' => json_encode($report, JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
        if (Auth::id()) {
            DB::update('users', ['last_ats_at' => now(), 'updated_at' => now()], 'id = ?', [Auth::id()]);
        }
        Response::html(View::render('pages/scan', [
            'title' => 'Compliance scan',
            'report' => $report,
            'resume' => $resume,
            'job' => $job,
        ]));
    }

    public function sitemap(): never
    {
        $seo = Settings::cached('seo', ['sitemapEnabled' => true, 'searchIndexing' => false]);
        if (empty($seo['sitemapEnabled'])) {
            Response::text('Sitemap disabled', 'text/plain', 404);
        }
        $urls = ['/', '/pricing', '/scan', '/tools', '/guides', '/contact', '/about'];
        foreach (ContentCatalog::tools() as $t) {
            $urls[] = '/calculators/' . $t[0];
        }
        foreach (ContentCatalog::guides() as $g) {
            $urls[] = '/guides/' . $g[0];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $u) {
            $xml .= '<url><loc>' . e(url($u)) . '</loc></url>';
        }
        $xml .= '</urlset>';
        Response::text($xml, 'application/xml');
    }

    public function robots(): never
    {
        $seo = Settings::cached('seo', ['searchIndexing' => false]);
        $body = empty($seo['searchIndexing'])
            ? "User-agent: *\nDisallow: /\n"
            : "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /workspace\nDisallow: /account\nSitemap: " . url('/sitemap.xml') . "\n";
        Response::text($body, 'text/plain');
    }

    public function unsubscribe(string $token): never
    {
        $lead = DB::fetch('SELECT * FROM leads WHERE unsubscribe_token = ?', [$token]);
        if ($lead) {
            DB::update('leads', ['stop_marketing' => 1, 'status' => 'unsubscribed'], 'id = ?', [$lead['id']]);
        }
        Response::html(View::render('pages/unsubscribe', ['title' => 'Unsubscribed', 'found' => (bool) $lead], 'layouts/auth'));
    }

    public function site(): never
    {
        Response::json(SiteFeed::payload());
    }

    public function track(): never
    {
        $raw = json_decode((string) file_get_contents('php://input'), true);
        $event = is_array($raw) ? (string) ($raw['event'] ?? '') : '';
        if (!in_array($event, ['preview', 'scan', 'prepare'], true)) {
            Response::json(['ok' => false], 422);
        }
        $path = mb_substr(trim((string) (is_array($raw) ? ($raw['path'] ?? '') : '')), 0, 190);
        DB::insert('events', [
            'user_id' => Auth::id(),
            'event' => $event,
            'path' => $path !== '' ? $path : null,
            'created_at' => now(),
        ]);
        Response::json(['ok' => true]);
    }

    public function openPixel(string $token): never
    {
        $lead = DB::fetch('SELECT id FROM leads WHERE unsubscribe_token = ?', [$token]);
        if ($lead) {
            DB::insert('events', ['user_id' => null, 'event' => 'email_open', 'path' => (string) $lead['id'], 'created_at' => now()]);
        }
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    private function marketingPage(string $file): never
    {
        $file = basename($file);
        if (!preg_match('/^[a-z0-9-]+\.html$/', $file)) {
            Response::html(View::render('pages/not-found', ['title' => 'Not found']), 404);
        }
        $path = dirname(__DIR__, 3) . '/public_html/' . $file;
        if (!is_file($path)) {
            Response::html(View::render('pages/not-found', ['title' => 'Not found']), 404);
        }
        $html = (string) file_get_contents($path);
        $signIn = Auth::check() ? 'href="/workspace">Workspace' : 'href="/login">Sign in';
        Response::html(strtr($html, [
            'href="home.html"' => 'href="/"',
            'href="pricing.html"' => 'href="/pricing"',
            'href="templates.html"' => 'href="/templates"',
            'href="tools.html"' => 'href="/tools"',
            'href="guides.html"' => 'href="/guides"',
            'href="about.html"' => 'href="/about"',
            'href="contact.html"' => 'href="/contact"',
            'href="privacy-policy.html"' => 'href="/privacy-policy"',
            'href="terms.html"' => 'href="/terms-and-conditions"',
            'href="refund-policy.html"' => 'href="/refund-policy"',
            'href="blog.html"' => 'href="/blog"',
            'href="feedback.html"' => 'href="/feedback"',
            'href="how-to-write-a-resume.html"' => 'href="/how-to-write-a-resume"',
            'href="resume-format.html"' => 'href="/resume-format"',
            'href="ats-friendly-resume.html"' => 'href="/ats-friendly-resume"',
            'href="resume-for-freshers.html"' => 'href="/resume-for-freshers"',
            'href="resume-score.html"' => 'href="/resume-score"',
            'href="in-hand-salary.html"' => 'href="/in-hand-salary"',
            'href="how-to-negotiate-salary.html"' => 'href="/how-to-negotiate-salary"',
            'href="cvtara-vs-other-resume-builders.html"' => 'href="/cvtara-vs-other-resume-builders"',
            'href="contact.html">Sign in' => $signIn,
            'href="/contact">Sign in' => $signIn,
        ]));
    }

    private function privacyText(): string
    {
        return "CVTara stores the account, resume drafts, scan history and billing references needed to run the workspace. Card numbers are entered on the payment provider and are not stored here. Marketing email is sent only after opt-in or an owner-recorded consent note. You can unsubscribe from every marketing message.";
    }

    private function termsText(): string
    {
        return "CVTara provides document, comparison and tracking tools. Scores are overlap measures, not hiring outcomes. Do not upload content you do not have the right to use. Paid access starts after the payment provider confirms the transaction.";
    }

    private function refundText(): string
    {
        return "Refund requests are reviewed against unused access time and verified payment records. Chargebacks after a referral payout are held for 14 days before commission becomes withdrawable.";
    }
}
