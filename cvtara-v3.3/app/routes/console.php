<?php

use App\Models\User;
use App\Services\CashfreePayouts;
use App\Services\MailAutomation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('cvtara:cron', function () {
    MailAutomation::reminders();
    CashfreePayouts::reconcile();
    $this->info(json_encode(MailAutomation::process()));
})->purpose('Process due mail and record cron health');
Schedule::command('cvtara:cron')->everyMinute()->withoutOverlapping();
Artisan::command('cvtara:admin {email} {name}', function () {
    $password = $this->secret('Owner password (12+ characters)');
    if (strlen($password) < 12) {
        $this->error('Use at least 12 characters.');

        return 1;
    } $u = User::firstOrCreate(['email' => $this->argument('email')], ['name' => $this->argument('name'), 'password' => Hash::make($password)]);
    $u->forceFill(['is_admin' => true])->save();
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
        $u->forceFill(['is_admin' => $admin, 'email_verified_at' => now(), 'referral_code' => $u->referral_code ?: Str::random(16)])->save();
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
    $doc = fn ($title, $role = '') => ['version' => '1', 'title' => $title, 'template' => 'suite-prism', 'accent' => '#1557d2', 'font' => 'Arial', 'personal' => ['name' => 'Demo Customer', 'email' => 'demo@cvtara.test', 'role' => $role ?: 'Data Analyst', 'city' => 'Bengaluru'], 'summary' => 'Analyst with hands-on SQL, Excel and dashboarding experience. Sample content for the preview.', 'order' => ['summary', 'experience', 'education', 'skills'], 'experience' => [['id' => '1', 'role' => 'Data Analyst Intern', 'company' => 'Sample Co', 'start' => '2025-01', 'current' => true, 'bullets' => "Built weekly reporting dashboards.\nCleaned and analysed survey data."]], 'education' => [['id' => '2', 'degree' => 'B.Sc. Statistics', 'school' => 'Sample University', 'end' => '2024']], 'projects' => [], 'certifications' => [], 'skills' => 'SQL, Excel, Power BI, Python'];
    \App\Services\Settings::put('integrations', array_merge(\App\Services\Settings::get('integrations'), ['payments' => true]));
    $owner = $mk('owner@cvtara.test', 'Demo Owner', true);
    $demo = $mk('demo@cvtara.test', 'Demo Customer');
    $buy($demo, 'fresher-pro', 4);
    if (! DB::table('resume_drafts')->where('owner_id', $demo->id)->exists()) {
        $pid = (string) Str::uuid();
        $ins = fn ($id, $title, $co, $role, $primary, $parent) => DB::table('resume_drafts')->insert(['id' => $id, 'owner_id' => $demo->id, 'title' => $title, 'template' => 'suite-prism', 'company' => $co, 'role' => $role, 'content_json' => json_encode($doc($title, $role)), 'revision' => 1, 'is_primary' => $primary, 'parent_id' => $parent, 'job_description' => $primary ? null : 'Sample job description for '.$role, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(1)]);
        $ins($pid, 'Primary resume — Data Analyst', '', 'Data Analyst', true, null);
        $ins((string) Str::uuid(), 'Business Analyst · Acme', 'Acme', 'Business Analyst', false, $pid);
        $ins((string) Str::uuid(), 'Data Analyst · Globex', 'Globex', 'Data Analyst', false, $pid);
        DB::table('cover_letter_drafts')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'title' => 'Letter — Acme', 'template' => 'letter-prism', 'company' => 'Acme', 'role' => 'Business Analyst', 'content_json' => json_encode(['version' => '1', 'title' => 'Letter — Acme', 'template' => 'letter-prism', 'accent' => '#1557d2', 'font' => 'Arial', 'personal' => ['name' => 'Demo Customer'], 'company' => 'Acme', 'role' => 'Business Analyst', 'opening' => 'I am applying for the Business Analyst role.', 'evidence' => 'Sample evidence paragraph for the preview.']), 'revision' => 1, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        foreach ([['Acme', 'Business Analyst', 'applied'], ['Globex', 'Data Analyst', 'interview'], ['Initech', 'Product Analyst', 'saved']] as [$c, $r, $st]) {
            DB::table('applications')->insert(['id' => (string) Str::uuid(), 'owner_id' => $demo->id, 'company' => $c, 'role' => $r, 'stage' => $st, 'job_url' => '', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $names = ['Aarav Mehta', 'Diya Nair', 'Kabir Shah', 'Ishita Rao', 'Rohan Das', 'Meera Iyer', 'Vihaan Gupta', 'Anaya Singh', 'Arjun Verma', 'Saanvi Reddy', 'Kiran Patel', 'Neha Joshi'];
    $plans = ['fresher-basic', 'fresher-standard', 'pro-basic', 'pro-standard', 'pro-pro', 'fresher-pro'];
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
    $this->info("Demo data ready. Customer: demo@cvtara.test · Owner: owner@cvtara.test · Password: {$pw}");
})->purpose('Seed demo accounts for the /preview shell (preview environments only)');
