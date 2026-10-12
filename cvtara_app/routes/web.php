<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CustomerController;
use App\Controllers\PublicController;
use App\Controllers\WebhookController;
use App\Middleware\Authenticate;
use App\Middleware\EnsureAdmin;
use App\Middleware\MaintenanceGate;
use App\Middleware\VerifyCsrf;

$web = [MaintenanceGate::class, VerifyCsrf::class];
$auth = [MaintenanceGate::class, VerifyCsrf::class, Authenticate::class];
$admin = [MaintenanceGate::class, VerifyCsrf::class, Authenticate::class, EnsureAdmin::class];

$router->get('/', [PublicController::class, 'home'], [MaintenanceGate::class]);
$router->get('/pricing', [PublicController::class, 'pricing'], [MaintenanceGate::class]);
$router->get('/templates', [PublicController::class, 'templates'], [MaintenanceGate::class]);
$router->get('/tools', [PublicController::class, 'tools'], [MaintenanceGate::class]);
$router->get('/guides', [PublicController::class, 'guides'], [MaintenanceGate::class]);
$router->get('/calculators/{slug}', [PublicController::class, 'tool'], [MaintenanceGate::class]);
$router->get('/guides/{a}/{b}', [PublicController::class, 'guide'], [MaintenanceGate::class]);
$router->get('/privacy-policy', fn () => (new PublicController())->legal('privacy-policy'), [MaintenanceGate::class]);
$router->get('/terms-and-conditions', fn () => (new PublicController())->legal('terms-and-conditions'), [MaintenanceGate::class]);
$router->get('/refund-policy', fn () => (new PublicController())->legal('refund-policy'), [MaintenanceGate::class]);
$router->get('/about', fn () => (new PublicController())->legal('about'), [MaintenanceGate::class]);
$router->get('/contact', [PublicController::class, 'contact'], [MaintenanceGate::class]);
$router->post('/contact', [PublicController::class, 'contactSubmit'], $web);
$router->get('/scan', [PublicController::class, 'scan'], [MaintenanceGate::class]);
$router->post('/scan', [PublicController::class, 'scanSubmit'], $web);
$router->get('/api/site', [PublicController::class, 'site']);
$router->post('/api/events', [PublicController::class, 'track']);
$router->get('/blog', fn () => (new PublicController())->homeFile('blog.html'), [MaintenanceGate::class]);
$router->get('/feedback', fn () => (new PublicController())->homeFile('feedback.html'), [MaintenanceGate::class]);
foreach ([
    'how-to-write-a-resume',
    'resume-format',
    'ats-friendly-resume',
    'resume-for-freshers',
    'resume-score',
    'in-hand-salary',
    'how-to-negotiate-salary',
    'cvtara-vs-other-resume-builders',
] as $article) {
    $router->get('/' . $article, static function () use ($article): void {
        (new PublicController())->homeFile($article . '.html');
    }, [MaintenanceGate::class]);
}
$router->get('/sitemap.xml', [PublicController::class, 'sitemap']);
$router->get('/robots.txt', [PublicController::class, 'robots']);
$router->get('/unsubscribe/{token}', [PublicController::class, 'unsubscribe']);
$router->get('/o/{token}', [PublicController::class, 'openPixel']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], [VerifyCsrf::class]);
$router->get('/register', [AuthController::class, 'showRegister'], [MaintenanceGate::class]);
$router->post('/register', [AuthController::class, 'register'], $web);
$router->post('/logout', [AuthController::class, 'logout'], [VerifyCsrf::class]);
$router->get('/forgot', [AuthController::class, 'showForgot']);
$router->post('/forgot', [AuthController::class, 'forgot'], [VerifyCsrf::class]);
$router->get('/reset/{token}', [AuthController::class, 'showReset']);
$router->post('/reset/{token}', [AuthController::class, 'reset'], [VerifyCsrf::class]);

$router->get('/workspace', [CustomerController::class, 'workspace'], $auth);
$router->get('/workspace/resume', [CustomerController::class, 'resume'], $auth);
$router->post('/workspace/resume', [CustomerController::class, 'resumeSave'], $auth);
$router->get('/workspace/match', [CustomerController::class, 'match'], $auth);
$router->post('/workspace/match', [CustomerController::class, 'matchSubmit'], $auth);
$router->get('/workspace/optimize', [CustomerController::class, 'tailor'], $auth);
$router->post('/workspace/optimize', [CustomerController::class, 'tailorSubmit'], $auth);
$router->get('/workspace/applications', [CustomerController::class, 'applications'], $auth);
$router->post('/workspace/applications', [CustomerController::class, 'applicationSave'], $auth);
$router->get('/checkout', [CustomerController::class, 'checkout'], $auth);
$router->post('/checkout', [CustomerController::class, 'checkoutStart'], $auth);
$router->get('/checkout/return', [CustomerController::class, 'checkoutReturn'], $auth);
$router->get('/wallet', [CustomerController::class, 'wallet'], $auth);
$router->post('/wallet/withdraw', [CustomerController::class, 'walletWithdraw'], $auth);
$router->get('/account', [CustomerController::class, 'account'], $auth);
$router->post('/account', [CustomerController::class, 'accountSave'], $auth);
$router->post('/account/feedback', [CustomerController::class, 'feedbackSave'], $auth);

$router->get('/admin', [AdminController::class, 'overview'], $admin);
$router->get('/admin/users', [AdminController::class, 'users'], $admin);
$router->post('/admin/purchases/{id}/mark-paid', [AdminController::class, 'markPaid'], $admin);
$router->get('/admin/operations', [AdminController::class, 'operations'], $admin);
$router->post('/admin/operations/maintenance', [AdminController::class, 'saveMaintenance'], $admin);
$router->post('/admin/operations/logs/clear', [AdminController::class, 'clearLogs'], $admin);
$router->post('/admin/operations/cache/clear', [AdminController::class, 'clearCache'], $admin);
$router->get('/admin/mailboxes', [AdminController::class, 'mailboxes'], $admin);
$router->post('/admin/mailboxes', [AdminController::class, 'mailboxSave'], $admin);
$router->get('/admin/leads', [AdminController::class, 'leads'], $admin);
$router->post('/admin/leads/import', [AdminController::class, 'leadImport'], $admin);
$router->post('/admin/leads/add', [AdminController::class, 'leadOptIn'], $admin);
$router->post('/admin/leads/outreach', [AdminController::class, 'runOutreach'], $admin);
$router->post('/admin/leads/abandoned', [AdminController::class, 'runAbandoned'], $admin);
$router->get('/admin/reviews', [AdminController::class, 'reviews'], $admin);
$router->post('/admin/reviews', [AdminController::class, 'reviewStatus'], $admin);
$router->get('/admin/settings', [AdminController::class, 'settings'], $admin);
$router->post('/admin/settings', [AdminController::class, 'settingsSave'], $admin);

$router->post('/webhooks/razorpay', [WebhookController::class, 'razorpay']);
$router->post('/webhooks/cashfree', [WebhookController::class, 'cashfree']);
$router->post('/api/v1/payments/cashfree-webhook', [WebhookController::class, 'cashfree']);
