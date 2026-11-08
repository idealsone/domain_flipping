<?php

use App\Models\User;
use App\Services\MailAutomation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('cvtara:cron', function () {
    \App\Services\Settings::put('cronHeartbeat', ['at' => now()->toIso8601String()]);
    // Mail that is already due goes first, so the daily backup or a slow scan never delays it.
    $send = function () {
        try {
            return MailAutomation::process();
        } catch (\Throwable $e) {
            \App\Services\ErrorLog::exception($e);

            return ['sent' => 0, 'processed' => 0, 'error' => 'mail run failed; see Error Log'];
        }
    };
    $mail = $send();
    MailAutomation::reminders();
    if (\Illuminate\Support\Facades\Cache::add('retention-run', 1, 86400)) {
        \App\Services\Retention::run();
    }
    if (\Illuminate\Support\Facades\Cache::add('backup-run', 1, 86400)) {
        try {
            \App\Services\Backup::run();
        } catch (\Throwable $e) {
            \App\Services\ErrorLog::exception($e);
        }
    }
    // Application status reminders were only sent by cvtara:remind, which the host cron never runs. Check hourly here instead.
    if (\Illuminate\Support\Facades\Cache::add('remind-run', 1, 3600)) {
        try {
            \App\Services\Tracker::remind();
        } catch (\Throwable $e) {
            \App\Services\ErrorLog::exception($e);
        }
    }
    // PhonePe orders whose customer closed the payment page before returning, and whose callback has not arrived.
    if (\Illuminate\Support\Facades\Cache::add('phonepe-reconcile', 1, 300)) {
        try {
            \App\Services\PhonePe::reconcile();
        } catch (\Throwable $e) {
            \App\Services\ErrorLog::exception($e);
        }
    }
    // New or changed public pages go to IndexNow (and Google, if the owner switched it on) once an hour.
    if (\Illuminate\Support\Facades\Cache::add('fast-index-run', 1, 3600)) {
        try {
            \App\Services\FastIndex::run();
        } catch (\Throwable $e) {
            \App\Services\ErrorLog::exception($e);
        }
    }
    try {
        \App\Services\Health::run();
    } catch (\Throwable $e) {
        \App\Services\ErrorLog::exception($e);
    }
    try {
        \App\Services\Campaigns::run();
    } catch (\Throwable $e) {
        \App\Services\ErrorLog::exception($e);
    }
    // Tara Expert Review orders waiting for Tara AI: a few per run, so the run stays inside the host's time limit.
    try {
        \App\Services\ExpertReview::run();
    } catch (\Throwable $e) {
        \App\Services\ErrorLog::exception($e);
    }
    // Then what this run queued (reminders, a campaign batch). Account mail goes before outreach, so slow outreach
    // mailboxes can never delay it.
    $again = $send();
    $mail = ['sent' => ($mail['sent'] ?? 0) + ($again['sent'] ?? 0), 'processed' => ($mail['processed'] ?? 0) + ($again['processed'] ?? 0)] + $again + $mail;
    try {
        \App\Services\Outreach::process();
    } catch (\Throwable $e) {
        \App\Services\ErrorLog::exception($e);
    }
    $this->info(json_encode($mail));
})->purpose('Process due mail and record cron health');
Artisan::command('cvtara:remind', function () {
    $this->info('Status reminders sent: '.\App\Services\Tracker::remind());
})->purpose('Send the due application status reminders (up to 3 per job)');
Artisan::command('cvtara:mail-preview {dir}', function (string $dir) {
    @mkdir($dir, 0775, true);
    foreach (\App\Services\Settings::defaults()['emails'] as $t) {
        if ($m = MailAutomation::preview($t['id'])) {
            file_put_contents($dir.'/'.$t['id'].'.html', $m['html']);
            file_put_contents($dir.'/'.$t['id'].'.txt', $m['subject']."\n\n".$m['text']);
        }
    }
    // Outreach (separate mailboxes): every mail of both lists, with a sample contact and mailbox.
    $c = \App\Services\Outreach::config();
    $sender = (object) ['id' => '00000000-0000-4000-8000-000000000000', 'email' => 'priya@cvtara.in', 'from_name' => 'Priya from CVTara'];
    foreach ($c['lists'] as $list => $l) {
        $ct = (object) ['id' => '00000000-0000-4000-8000-000000000001', 'email' => 'sample@example.com', 'name' => $list === 'colleges' ? 'Dr. Ramesh Kumar' : 'Asha Rao', 'list' => $list, 'college' => 'ABC College of Engineering', 'designation' => 'Training and Placement Officer', 'city' => 'Mysuru', 'step' => 0];
        foreach ($l['steps'] as $i => $step) {
            $ct->step = $i;
            $m = \App\Services\Outreach::render($step, $ct, $sender, $c);
            file_put_contents($dir.'/outreach-'.$list.'-'.($i + 1).'.html', $m['html']);
            file_put_contents($dir.'/outreach-'.$list.'-'.($i + 1).'.txt', $m['subject']."\n\n".$m['text']);
        }
    }
    $this->info('Wrote '.count(glob($dir.'/*.html')).' email previews to '.$dir);
})->purpose('Write every email template, filled with sample values, as HTML and text files');
Artisan::command('cvtara:backup', function () {
    $this->info('Backup written to '.\App\Services\Backup::run());
})->purpose('Write a compressed database backup to storage/app/backups');
Schedule::command('cvtara:cron')->everyMinute()->withoutOverlapping();
Artisan::command('cvtara:admin {email} {name}', function () {
    $password = $this->secret('Owner password (12+ characters)');
    if (strlen($password) < 12) {
        $this->error('Use at least 12 characters.');

        return 1;
    } $u = User::firstOrCreate(['email' => strtolower($this->argument('email'))], ['name' => $this->argument('name'), 'password' => Hash::make($password)]);
    $u->forceFill(['is_admin' => true, 'password' => Hash::make($password), 'email_verified_at' => $u->email_verified_at ?: now()])->save();
    $this->info('Owner account is ready.');
});

// Seeds clearly-labelled demo accounts and sample data for the /preview shell. Disabled unless CVTARA_PREVIEW=true.
Artisan::command('cvtara:demo', function () {
    if (! config('app.preview')) {
        $this->error('Set CVTARA_PREVIEW=true in .env to use demo data. Never enable this on a live site.');

        return 1;
    }
    $pw = config('app.demo_password');
    $mk = function ($email, $name, $admin = false, $level = 'fresher') use ($pw) {
        $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($pw)]);
        $u->forceFill(['is_admin' => $admin, 'email_verified_at' => now(), 'referral_code' => $u->referral_code ?: \App\Services\Referrals::makeCode((string) $u->name)])->save();
        DB::table('profiles')->updateOrInsert(['owner_id' => $u->id], ['name' => $name, 'experience_level' => $level, 'profession' => $admin ? 'Owner' : 'Data Analyst', 'category' => 'Technology', 'onboarding_done' => true, 'marketing_consent' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    };
    $buy = function ($u, $key, $daysAgo = 3) {
        $p = \App\Services\Settings::plans()[$key];
        if (DB::table('purchases')->where('owner_id', $u->id)->where('plan', $key)->exists()) {
            return;
        }
        DB::table('purchases')->insert(['id' => (string) Str::uuid(), 'owner_id' => $u->id, 'plan' => $key, 'amount_paise' => $p['price'] * 100, 'provider' => 'demo', 'status' => 'paid', 'is_live' => false, 'limits_json' => json_encode($p), 'access_days' => $p['accessDays'], 'payment_reference' => 'demo_'.Str::random(8), 'purchased_at' => now()->subDays($daysAgo), 'access_until' => now()->subDays($daysAgo)->addDays($p['accessDays']), 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()]);
    };
    $doc = fn ($title, $role = '') => ['version' => '1', 'title' => $title, 'template' => 'suite-prism', 'accent' => '#1557d2', 'font' => 'Arial', 'personal' => ['name' => 'Demo Customer', 'email' => 'demo@cvtara.test', 'phone' => '+91 90000 00000', 'role' => $role ?: 'Data Analyst', 'city' => 'Bengaluru', 'linkedin' => 'linkedin.com/in/demo-customer'], 'summary' => 'Analyst with two years of hands-on SQL, Excel and dashboarding experience, turning messy survey and sales data into clear weekly reports that managers use to decide. Comfortable presenting findings to stakeholders. Sample content for the preview.', 'order' => ['summary', 'experience', 'education', 'skills'], 'experience' => [['id' => '1', 'role' => 'Data Analyst Intern', 'company' => 'Sample Co', 'start' => '2025-01', 'current' => true, 'bullets' => "Built weekly reporting dashboards in Power BI used by 12 managers.\nCleaned and analysed survey data of 8,000 responses in SQL and Excel.\nReduced monthly reporting time by 35% by automating Excel reports.\nPresented insights to stakeholders and coordinated fixes with the product team."], ['id' => '3', 'role' => 'Research Assistant', 'company' => 'Sample University', 'start' => '2023-06', 'end' => '2024-12', 'bullets' => "Analysed 5 years of enrolment data and improved forecast accuracy by 18%.\nPrepared charts and summary tables for faculty reports.\nMaintained the department data sets and documented every change."]], 'education' => [['id' => '2', 'degree' => 'B.Sc. Statistics', 'school' => 'Sample University', 'end' => '2024']], 'projects' => [], 'certifications' => [], 'skills' => 'SQL, Excel, Power BI, Python, Tableau, Data cleaning, Reporting, Stakeholder communication, Statistics'];
    \App\Services\Settings::put('integrations', array_merge(\App\Services\Settings::get('integrations'), ['payments' => true]));
    $owner = $mk('owner@cvtara.test', 'Demo Owner', true);
    $demo = $mk('demo@cvtara.test', 'Demo Customer');
    $buy($demo, 'pro', 4);
    if (! DB::table('resume_drafts')->where('owner_id', $demo->id)->exists()) {
        $pid = (string) Str::uuid();
        $ins = fn ($id, $title, $co, $role, $primary, $parent) => DB::table('resume_drafts')->insert(['id' => $id, 'owner_id' => $demo->id, 'title' => $title, 'template' => 'suite-prism', 'company' => $co, 'role' => $role, 'content_json' => json_encode($doc($title, $role)), 'revision' => 1, 'is_primary' => $primary, 'parent_id' => $parent, 'job_description' => $primary ? null : 'Sample job description for '.$role, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(1)]);
        $ins($pid, 'Primary resume — Data Analyst', '', 'Data Analyst', true, null);
        // Sample checks and feedback so the preview shows a lived-in ATS history and support inbox.
        $jobs = [['Business Analyst', 'Infosys', 'We are hiring a Business Analyst. Responsibilities: gather requirements, write SQL queries, build Power BI dashboards and present insights to stakeholders. Required: 3+ years of experience in analytics, strong SQL, Excel and stakeholder communication. Preferred: Python, Tableau.', 30], ['Data Analyst', 'Beta Labs', 'Data Analyst role. You will analyse large data sets, build dashboards in Tableau, write SQL and present insights. Required: SQL, Excel, 2+ years of experience. Preferred: Python. Bachelor degree required.', 80]];
        foreach ($jobs as [$jt, $jc, $jx, $hrs]) {
            $rep = \App\Services\AtsEngine::report($doc('Primary resume — Data Analyst', 'Data Analyst'), $jx, $jt);
            DB::table('ats_scans')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'resume_id' => $pid, 'source' => 'text', 'job_url' => '', 'job_title' => $jt, 'company' => $jc, 'job_text' => $jx, 'score' => $rep['score'], 'result_json' => json_encode($rep + ['details' => []]), 'status' => 'done', 'created_at' => now()->subHours($hrs), 'updated_at' => now()->subHours($hrs)]);
        }
        foreach ([['ats-match', 'bug', 'The keyword list shows a skill as missing even though it is in my resume summary.', 3, 'new', 2], ['downloads', 'question', 'If I edit my resume after downloading, does the next download use another credit?', null, 'seen', 20], ['website', 'idea', 'Please add a Hindi version of the site. Many friends would use it.', 4, 'working', 40], ['billing', 'praise', 'Checkout was quick and the plan details were clear. Thank you!', 5, 'resolved', 90]] as [$ar, $kd, $msg, $rt, $stt, $hrs]) {
            DB::table('support_reports')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'name' => $demo->name, 'email' => $demo->email, 'area' => $ar, 'kind' => $kd, 'page' => '/workspace.html', 'message' => $msg, 'rating' => $rt, 'status' => $stt, 'plan' => 'Pro', 'viewport' => '1366x768', 'user_agent' => 'Demo browser', 'resolved_at' => $stt === 'resolved' ? now()->subHours($hrs - 5) : null, 'created_at' => now()->subHours($hrs), 'updated_at' => now()->subHours($hrs)]);
        }
        $ins((string) Str::uuid(), 'Business Analyst · Acme', 'Acme', 'Business Analyst', false, $pid);
        $ins((string) Str::uuid(), 'Data Analyst · Globex', 'Globex', 'Data Analyst', false, $pid);
        DB::table('cover_letter_drafts')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'title' => 'Letter — Acme', 'template' => 'letter-prism', 'company' => 'Acme', 'role' => 'Business Analyst', 'content_json' => json_encode(['version' => '1', 'title' => 'Letter — Acme', 'template' => 'letter-prism', 'accent' => '#1557d2', 'font' => 'Arial', 'personal' => ['name' => 'Demo Customer'], 'company' => 'Acme', 'role' => 'Business Analyst', 'opening' => 'I am applying for the Business Analyst role.', 'evidence' => 'Sample evidence paragraph for the preview.']), 'revision' => 1, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        foreach ([['Acme', 'Business Analyst', 'applied', 'manual', 0], ['Globex', 'Data Analyst', 'interview', 'ats', 2], ['Initech', 'Product Analyst', 'saved', 'prepared', 1], ['Nimbus', 'Business Analyst', 'saved', 'ats', 0]] as [$c, $r, $st, $origin, $sent]) {
            DB::table('applications')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'company' => $c, 'role' => $r, 'stage' => $st, 'job_url' => '', 'revision' => 1, 'origin' => $origin, 'reminders_sent' => $sent, 'next_reminder_at' => $origin === 'manual' ? null : now()->addDays(3), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'kind' => 'application', 'title' => 'Business Analyst at Nimbus: what is the status now?', 'body' => 'Marked "Saved". Have you applied yet? Mark it as Applied once you have. (Reminder 1 of 3)', 'link' => '/workspace.html?view=tracker', 'ref' => 'demo:1', 'created_at' => now(), 'updated_at' => now()]);
    }
    // Sample offers so the preview shows the Offers & festivals page with something live.
    if (! \App\Services\Promos::all()) {
        $paid = \App\Services\Settings::paidPlanKeys();
        $bc = (array) \App\Services\Settings::get('business_controls', []);
        $bc['coupons'] = [['code' => 'DIWALI25', 'type' => 'percent', 'value' => 25, 'plans' => $paid, 'expiresAt' => '', 'enabled' => true, 'maxUses' => 0, 'perUser' => 1], ['code' => 'EARLY100', 'type' => 'flat', 'value' => 100, 'plans' => $paid, 'expiresAt' => '', 'enabled' => true, 'maxUses' => 200, 'perUser' => 1]];
        \App\Services\Settings::put('business_controls', $bc);
        $mk = fn ($name, $tpl, $theme, $head, $sub, $badge, $code, $mode, $extra = []) => array_merge(['id' => (string) Str::uuid(), 'mode' => $mode, 'name' => $name, 'template' => $tpl, 'theme' => $theme, 'heading' => $head, 'subheading' => $sub, 'body' => '', 'badge' => $badge, 'cta' => 'Claim this offer', 'couponCode' => $code, 'planKey' => '', 'imageUrl' => '', 'startsAt' => '', 'endsAt' => '', 'banner' => true, 'popup' => true, 'popupDelay' => 20, 'exit' => true, 'idle' => true, 'idleSeconds' => 45, 'exitHeading' => 'Wait, your offer is still here', 'exitText' => 'Claim it before you go. It applies automatically at checkout.', 'idleHeading' => 'Still thinking? This offer is waiting', 'idleText' => 'Take a minute: your coupon is ready.', 'pages' => 'all', 'frequencyHours' => 12, 'priority' => 0], $extra);
        \App\Services\Promos::put([
            $mk('Diwali '.date('Y'), 'diwali', 'diwali', 'Diwali Dhamaka {{year}}', 'Light up your career. Festive prices on every plan.', 'FESTIVE OFFER', 'DIWALI25', 'live', ['endsAt' => date('Y-m-d', strtotime('+5 days')).'T23:59']),
            $mk('Early-bird', 'earlybird', 'earlybird', 'Early-bird price for early starters', 'Get the lowest price of the season, then keep it.', 'EARLY BIRD', 'EARLY100', 'off'),
            $mk('Flash sale', 'flash', 'flash', 'Flash sale', 'A short-run discount on every plan.', 'FLASH SALE', 'DIWALI25', 'off'),
        ]);
    }
    $names = ['Aarav Mehta', 'Diya Nair', 'Kabir Shah', 'Ishita Rao', 'Rohan Das', 'Meera Iyer', 'Vihaan Gupta', 'Anaya Singh', 'Arjun Verma', 'Saanvi Reddy', 'Kiran Patel', 'Neha Joshi'];
    $plans = ['quick', 'job-hunt', 'pro', 'job-hunt', 'quick', 'pro'];
    foreach ($names as $i => $n) {
        $u = $mk(strtolower(str_replace(' ', '.', $n)).'@demo.cvtara.test', $n, false, $i % 3 === 0 ? 'experienced' : 'fresher');
        DB::table('users')->where('id', $u->id)->update(['created_at' => now()->subDays($i + 1)]);
        if ($i % 2 === 0) {
            $buy($u, $plans[$i % 6], $i + 1);
        }
        foreach (['registered', 'resume_saved', 'resume_compared'] as $ev) {
            if (! DB::table('activity_events')->where('owner_id', $u->id)->where('event', $ev)->exists()) {
                DB::table('activity_events')->insert(['id' => (string) Str::uuid(), 'owner_id' => $u->id, 'event' => $ev, 'reference' => '', 'created_at' => now()->subHours($i * 3 + 1), 'updated_at' => now()]);
            }
        }
    }
    if (! DB::table('feedback')->where('owner_id', $demo->id)->exists()) {
        DB::table('feedback')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'display_name' => 'Demo Customer', 'rating' => 5, 'content' => 'Sample review for the preview — the primary resume plus tailored versions saved me hours.', 'consent' => true, 'consented_at' => now(), 'status' => 'published', 'source' => 'owner_verified', 'consent_reference' => 'demo data', 'created_at' => now(), 'updated_at' => now()]);
    }

    // richer preview data: reviews waiting for moderation, a refunded order, sample error-log rows and health history
    foreach ([['Priya Sharma', 'Software Engineer, TCS · Mumbai', 5, 'The primary resume plus tailored versions saved me hours — I applied to eight roles in one evening.'], ['Rahul Verma', 'Associate, Zomato · Surat', 4, 'Very helpful for freshers. The ATS match showed me exactly which keywords I was missing.']] as [$n, $role, $rt, $txt]) {
        if (! DB::table('feedback')->where('display_name', $n)->exists()) {
            DB::table('feedback')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'display_name' => $n.' · '.$role, 'rating' => $rt, 'content' => $txt, 'status' => 'pending', 'source' => 'owner_verified', 'consent' => true, 'consented_at' => now(), 'consent_reference' => 'demo data', 'created_at' => now()->subHours(5), 'updated_at' => now()]);
        }
    }
    $someone = User::where('email', 'saanvi.reddy@demo.cvtara.test')->first();
    if ($someone && ! DB::table('purchases')->where('owner_id', $someone->id)->exists()) {
        $p = \App\Services\Settings::plans()['job-hunt'];
        DB::table('purchases')->insert(['id' => (string) Str::uuid(), 'owner_id' => $someone->id, 'plan' => 'job-hunt', 'amount_paise' => $p['price'] * 100, 'refunded_paise' => $p['price'] * 100, 'provider' => 'demo', 'status' => 'refunded', 'is_live' => false, 'payment_reference' => 'demo_refunded', 'limits_json' => json_encode($p), 'access_days' => $p['accessDays'], 'purchased_at' => now()->subDays(9), 'access_until' => now()->subDays(1), 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(6)]);
    }
    if (! DB::table('error_logs')->exists()) {
        \App\Services\ErrorLog::record('error', 'browser', '[sample] TypeError: Cannot read properties of undefined (reading \'name\')', 'workspace-ui.js:88', '/workspace.html');
        \App\Services\ErrorLog::record('warning', 'payment', '[sample] Razorpay webhook received for an unknown order — retried automatically', 'webhooks/razorpay', '/webhooks/razorpay');
        \App\Services\ErrorLog::record('error', 'server', '[sample] PDOException: SQLSTATE[HY000] Lock wait timeout exceeded; try restarting transaction', 'Payments.php:71');
        \App\Services\ErrorLog::record('info', 'cron', '[sample] Daily health check completed with no problems', 'health:'.now()->subDay()->toDateString());
        DB::table('error_logs')->orderBy('id')->limit(3)->update(['occurrences' => 3, 'first_seen' => now()->subDays(2)]);
    }
    $hist = [];
    foreach (range(6, 1) as $d) {
        $hist[] = ['at' => now()->subDays($d)->toIso8601String(), 'date' => now()->subDays($d)->toDateString(), 'status' => $d === 4 ? 'attention' : 'healthy', 'checks' => [], 'metrics' => ['users' => 8 + (6 - $d), 'paidActive' => 4 + (6 - $d) % 3, 'mailQueued' => 0, 'mailFailed24h' => $d === 4 ? 2 : 0, 'errors24h' => $d === 4 ? 3 : 0, 'diskFreePct' => 71], 'problems' => $d === 4 ? ['emailConfigured'] : []];
    }
    \App\Services\Settings::put('healthHistory', array_reverse($hist));
    \App\Services\Health::run(true);
    $this->info("Demo data ready. Customer: demo@cvtara.test · Owner: owner@cvtara.test · Password: {$pw}");
})->purpose('Seed demo accounts for the /preview shell (preview environments only)');

// Test helper: runs the ATS engine on a JSON file of [resume, job] pairs and prints the results (used by the automated tests).
Artisan::command('cvtara:ats-analyse {file}', function (string $file) {
    $pairs = json_decode((string) file_get_contents($file), true) ?: [];
    $this->line(json_encode(array_map(fn ($p) => \App\Services\AtsEngine::analyse($p[0], $p[1]), $pairs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
})->purpose('Run the ATS engine on resume/job pairs');

Artisan::command('cvtara:prebuild', function () {
    $r = \App\Services\PageCache::build();
    $this->info('Pre-built '.$r['built'].' public pages'.($r['skipped'] ? '; could not build: '.implode(', ', $r['skipped']) : '').'.');
})->purpose('Render every public page to a ready-made file so visitors are served without the database');
