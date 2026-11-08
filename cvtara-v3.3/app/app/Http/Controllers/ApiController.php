<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\AffiliateProfiles;
use App\Services\BusinessControls;
use App\Services\Credits;
use App\Services\JobPage;
use App\Services\MailAutomation;
use App\Services\Payments;
use App\Services\ReferralRewards;
use App\Services\Referrals;
use App\Services\Settings;
use App\Services\WritingAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiController extends Controller
{
    public function run(Request $r, string $path = '')
    {
        $u = $r->user();
        $id = $u?->id;
        $get = $r->isMethod('GET');
        if ($get && preg_match('#^(checkout/|ats/scan$|traffic$|security/(send|verify)-code|referrals/(withdraw|creator|payout-profile)$|account/delete$|feedback/[^/]+/withdraw$)#', $path)) {
            abort(405);
        }if ($r->isMethod('DELETE') && ! preg_match('#^(resumes|letters|applications)/[a-f0-9-]{36}$#', $path)) {
            abort(405);
        }$d = $r->all();
        $ok = fn ($a = []) => response()->json(['ok' => true, ...$a]);
        if ($path === 'csrf' && $get) {
            return $ok(['token' => csrf_token()]);
        }
        if ($path === 'health') {
            return $ok(['storage' => DB::connection()->getPdo() ? 'available' : 'unavailable']);
        }
        if ($path === 'business-public' && $get) {
            $d = BusinessControls::get();

            $plans = Settings::plans();
            // A CVTara team add-on this customer has already ordered the most times allowed today is hidden until tomorrow.
            foreach ($id ? \App\Services\Services::hiddenToday($id) : [] as $k) {
                $plans[$k]['limitReached'] = true;
            }

            return $ok(['brand' => $d['brand'], 'growth' => $d['growth'], 'plans' => $plans]);
        }
        if ($path === 'public-config') {
            return $ok(['settings' => array_merge(['heroTitle' => 'Your next resume starts with your real experience.', 'heroDescription' => 'Choose a design. Tell your story. Prepare a focused application.', 'primaryCTA' => 'Build my resume', 'secondaryCTA' => 'Check my resume', 'accent' => '#1557d2', 'pushEnabled' => false, 'pushDelay' => 8, 'pushMessage' => 'Get useful resume tips and career guides.'], Settings::get('public', []), ['oneSignalAppId' => (Settings::get('public', [])['oneSignalAppId'] ?? '') ?: (Settings::providers()['push']['oneSignalAppId'] ?? ''), 'pushEnabled' => (Settings::get('integrations')['push'] ?? false) && (Settings::get('public', [])['pushEnabled'] ?? false)])]);
        }
        if ($path === 'promo' && $get) {
            $offer = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $r->query('offer', '')));
            $promo = \Illuminate\Support\Facades\Cache::remember(\App\Services\Promos::cacheKey($offer), 30, fn () => \App\Services\Promos::active($offer ?: null));

            return $ok(['promo' => $promo, 'serverTime' => gmdate('c')]);
        }
        if ($path === 'promo/event' && $r->isMethod('POST')) {
            $v = $r->validate(['id' => 'required|string|max:40', 'kind' => 'required|in:shown,click,copy,close']);
            \App\Services\Promos::track($v['id'], $v['kind']);

            return $ok();
        }
        if ($path === 'public-activity') {
            $cfg = array_merge(['enabled' => true, 'delaySeconds' => 90, 'showSeconds' => 8, 'gapSeconds' => 25, 'useLiveActivity' => true, 'showPurchases' => true, 'custom' => []], (array) Settings::get('popup', []));
            $messages = \Illuminate\Support\Facades\Cache::remember('public-activity', 60, function () use ($cfg) {
                $out = [];
                foreach ($cfg['custom'] as $m) {
                    if (! empty($m['enabled']) && ! empty($m['text'])) {
                        $out[] = ['icon' => $m['icon'] ?? '✨', 'title' => $m['title'] ?? 'CVTara', 'text' => $m['text']];
                    }
                }
                // Real buyers who chose to be named (first name and city only). Nothing here is made up.
                if ($cfg['showPurchases']) {
                    $plans = Settings::plans();
                    foreach (DB::table('purchases')->join('profiles', 'profiles.owner_id', '=', 'purchases.owner_id')->where('purchases.status', 'paid')->where('purchases.amount_paise', '>', 0)->where('purchases.provider', '!=', 'referral_reward')->where('profiles.show_in_activity', true)->where('purchases.created_at', '>=', now()->subDays(14))->orderByDesc('purchases.created_at')->limit(8)->get(['purchases.plan', 'purchases.created_at as at', 'profiles.name', 'profiles.city']) as $p) {
                        $first = Str::of($p->name)->trim()->before(' ')->limit(20, '')->toString();
                        if ($first === '' || empty($plans[$p->plan])) {
                            continue;
                        }
                        $out[] = ['kind' => 'purchase', 'icon' => '🛍️', 'name' => Str::title(mb_strtolower($first)), 'city' => Str::title(mb_strtolower(trim((string) $p->city))), 'plan' => $plans[$p->plan]['name'], 'at' => \Illuminate\Support\Carbon::parse($p->at, 'UTC')->toIso8601String()];
                    }
                }
                if ($cfg['useLiveActivity']) {
                    $count = fn ($event, $days) => DB::table('activity_events')->where('event', $event)->where('created_at', '>=', now()->subDays($days))->count();
                    foreach ([['resume_saved', 1, '📄', 'Resumes saved today', '%d resumes were saved on CVTara in the last 24 hours.', 5], ['resume_compared', 1, '🎯', 'ATS checks today', '%d resumes were checked against job descriptions in the last 24 hours.', 5], ['registered', 7, '👋', 'New this week', '%d job seekers joined CVTara in the last 7 days.', 5], ['purchase_verified', 7, '🚀', 'Plans started', '%d people started a plan in the last 7 days.', 3]] as [$event, $days, $icon, $title, $text, $min]) {
                        $n = $count($event, $days);
                        if ($n >= $min) {
                            $out[] = ['icon' => $icon, 'title' => $title, 'text' => sprintf($text, $n)];
                        }
                    }
                    foreach (Settings::plans() as $k => $p) {
                        if (($p['type'] ?? '') === 'plan' && ! empty($p['popular']) && ($p['mrp'] ?? 0) > $p['price']) {
                            $out[] = ['icon' => '⭐', 'title' => $p['name'], 'text' => sprintf('%s is ₹%s for %d days — save ₹%s today.', $p['name'], number_format($p['price']), $p['accessDays'], number_format($p['mrp'] - $p['price'])), 'href' => '/checkout.html?plan='.$k];
                        }
                    }
                }

                return $out;
            });

            return $ok(['settings' => ['enabled' => (bool) $cfg['enabled'], 'delaySeconds' => (int) $cfg['delaySeconds'], 'showSeconds' => (int) $cfg['showSeconds'], 'gapSeconds' => (int) $cfg['gapSeconds']], 'messages' => $messages, 'now' => gmdate('c')]);
        }
        if ($path === 'public-content') {
            $content = [];
            foreach (['legal', 'features', 'library', 'blog', 'integrations', 'seo', 'site'] as $k) {
                $content[$k] = Settings::get($k);
            }$content['plans'] = Settings::plans();
            $content['jobLinks'] = (bool) (((array) Settings::get('speed', []))['jobLinks'] ?? false);
            $content['integrations']['measurementId'] = Settings::providers()['analytics']['measurementId'] ?? '';
            foreach ($content['blog'] as $slug => &$post) {
                if (! $post['published']) {
                    $post = ['published' => false];
                } elseif ($r->query('slug') !== $slug) {
                    $post['body'] = '';
                    $post['faq'] = '';
                }
            }

            return $ok(['content' => $content, 'reviews' => DB::table('feedback')->where('status', 'published')->where('consent', true)->get(['id', 'display_name', 'rating', 'content', 'created_at'])]);
        }
        if ($path === 'traffic') {
            if (($d['consent'] ?? false) && Settings::get('integrations')['analytics']) {
                $p = $r->validate(['path' => ['required','regex:#^/[a-zA-Z0-9/_.-]*$#','not_regex:#\.\.#','max:200'], 'source' => 'required|in:direct,google,bing,social,referral']);
                if (! preg_match('/admin|workspace|setup/', $p['path'])) {
                    $visitor = hash('sha256', $r->session()->getId());
                    if (! DB::table('page_views')->where('visitor_id', $visitor)->where('path', $p['path'])->where('created_at', '>', now()->subMinute())->exists()) {
                        DB::table('page_views')->insert([...$p, 'visitor_id' => $visitor, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }

            return $ok();
        }
        abort_unless($u, 401);
        abort_if($u->blocked, 403);
        DB::table('profiles')->insertOrIgnore(['owner_id' => $id, 'name' => $u->name, 'created_at' => now(), 'updated_at' => now()]);
        if (str_starts_with($path, 'admin/')) {
            abort_unless($u->is_admin, 403);

            return app(AdminController::class)->run($r, substr($path, 6));
        }
        if ($path === 'referral-rewards' && $get) {
            return $ok(ReferralRewards::status($u));
        }
        if (str_starts_with($path, 'referral-rewards/')) {
            abort_unless($r->isMethod('POST'), 405);
            if ($path === 'referral-rewards/claim') {
                $r->validate(['milestone' => 'required|integer|in:5']);
                ReferralRewards::claim($u, $r->integer('milestone'));
            } else {
                abort(404);
            }

            return $ok();
        }
        if ($path === 'security/status') {
            return $ok(['verified' => (bool) $u->email_verified_at, 'recent' => config('app.preview') || $r->session()->get('sensitive_until', 0) > now()->timestamp && $r->session()->get('sensitive_user') === $u->id]);
        }
        if ($path === 'security/send-code') {
            AccountSecurity::send($r);

            return $ok();
        }
        if ($path === 'security/verify-code') {
            AccountSecurity::verify($r);

            return $ok();
        }
        if ($path === 'referrals/creator') {
            $v = $r->validate(['links' => 'required|array|min:1|max:5', 'links.*' => 'required|url:https|max:500', 'audience' => 'required|string|min:10|max:500', 'consent' => 'accepted']);
            AffiliateProfiles::creator($u, $v);

            return $ok();
        }
        if ($path === 'referrals/payout-profile') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['name' => 'required|string|min:3|max:100', 'phone' => 'required|regex:/^[6-9][0-9]{9}$/', 'address' => 'required|string|min:10|max:500', 'country' => 'required|in:IN', 'method' => 'required|in:upi,bank', 'upi' => 'required_if:method,upi|nullable|regex:/^[a-zA-Z0-9._-]{2,100}@[a-zA-Z0-9]{2,40}$/', 'account' => 'required_if:method,bank|nullable|regex:/^[0-9]{9,18}$/', 'ifsc' => 'required_if:method,bank|nullable|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/', 'pan' => (Referrals::config()['requirePan'] ? 'required' : 'nullable').'|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'consent' => 'accepted']);
            AffiliateProfiles::save($u, $v);

            return $ok();
        }
        if ($path === 'profile' && $get) {
            $p = DB::table('profiles')->where('owner_id', $id)->first();
            $p->email = $u->email;

            return $ok(['profile' => $p, 'isOwner' => (bool) $u->is_admin, 'isPaid' => (bool) (Settings::access($id)['paid'] ?? false) || (bool) $u->is_admin, 'user' => ['id' => $id, 'email' => $u->email]]);
        }
        if ($path === 'profile') {
            $v = $r->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40', 'language' => 'sometimes|in:en', 'experienceLevel' => 'required|in:fresher,experienced', 'profession' => 'required|string|max:120', 'category' => 'required|in:Technology,HR & Recruitment,Sales & Marketing,Finance,Operations,Education,Healthcare,Design,Other', 'experienceYears' => 'required|integer|min:0|max:60', 'city' => 'nullable|string|max:120', 'marketingConsent' => 'required|boolean']);
            DB::table('profiles')->where('owner_id', $id)->update(['name' => $v['name'], 'phone' => $v['phone'] ?? '', 'language' => 'en', 'experience_level' => $v['experienceLevel'], 'profession' => $v['profession'], 'category' => $v['category'], 'experience_years' => $v['experienceLevel'] === 'fresher' ? 0 : $v['experienceYears'], 'city' => $v['city'] ?? '', 'marketing_consent' => $v['marketingConsent'], 'onboarding_done' => true, 'updated_at' => now()]);
            $u->name = $v['name'];
            $u->save();
            Settings::audit($id, 'profile_completed');

            return $ok();
        }
        if ($path === 'writing/check' && $r->isMethod('POST')) {
            $v = $r->validate(['type' => 'required|in:resume,letter', 'id' => 'nullable|uuid', 'data' => 'required_without:id|array', 'full' => 'sometimes|boolean']);
            $rk = 'writing:'.$id;
            abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rk, 30), 429, 'Too many checks in a minute. Wait a moment and try again.');
            \Illuminate\Support\Facades\RateLimiter::hit($rk, 60);
            $data = $v['data'] ?? null;
            if (! empty($v['id'])) {
                $row = DB::table($v['type'] === 'resume' ? 'resume_drafts' : 'cover_letter_drafts')->where('owner_id', $id)->where('id', $v['id'])->first(['content_json']);
                abort_unless($row, 404);
                $data = json_decode((string) $row->content_json, true) ?: [];
            }
            abort_if(strlen((string) json_encode($data)) > 400000, 413);

            return $ok(['check' => \App\Services\WritingCheck::run($v['type'], (array) $data, $id, (bool) ($v['full'] ?? false))]);
        }
        // One-click cover letter: a complete letter from a saved resume and a job (pasted text, or the job of an ATS check).
        if ($path === 'letters/draft' && $r->isMethod('POST')) {
            $v = $r->validate(['resumeId' => ['required', 'regex:/^[a-f0-9-]{36}$/'], 'scanId' => ['nullable', 'regex:/^[a-f0-9-]{36}$/'], 'role' => 'nullable|string|max:160', 'company' => 'nullable|string|max:160', 'recipient' => 'nullable|string|max:180', 'jobText' => 'nullable|string|max:40000']);
            abort_unless(Settings::get('features')['letter']['enabled'], 403);
            $key = 'letter-draft:'.$id;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 8)) {
                throw ValidationException::withMessages(['letter' => 'Please wait a moment before writing another letter.']);
            }
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            $resume = DB::table('resume_drafts')->where('id', $v['resumeId'])->where('owner_id', $id)->first(['content_json', 'job_description', 'role', 'company']);
            abort_unless($resume, 422, 'Choose one of your saved resumes.');
            $job = ['role' => $v['role'] ?? '', 'company' => $v['company'] ?? '', 'recipient' => $v['recipient'] ?? '', 'text' => (string) ($v['jobText'] ?? '')];
            if (! empty($v['scanId'])) {
                $sc = DB::table('ats_scans')->where('id', $v['scanId'])->where('owner_id', $id)->where('status', 'done')->first(['job_text', 'job_title', 'company']);
                abort_unless($sc, 422, 'That ATS check was not found.');
                $job = [...$job, 'text' => trim($job['text']) !== '' ? $job['text'] : (string) $sc->job_text, 'role' => $job['role'] ?: (string) $sc->job_title, 'company' => $job['company'] ?: (string) $sc->company];
            }
            // A resume tailored for a job keeps that job's text, role and company.
            $job = [...$job, 'text' => trim($job['text']) !== '' ? $job['text'] : (string) ($resume->job_description ?? ''), 'role' => $job['role'] ?: (string) ($resume->role ?? ''), 'company' => $job['company'] ?: (string) ($resume->company ?? '')];
            $data = json_decode((string) $resume->content_json, true) ?: [];
            abort_if(mb_strlen(\App\Services\AtsEngine::resumeText($data)) < 60, 422, 'This resume has too little content to write from. Add your experience in the builder first.');
            $out = \App\Services\CoverLetterWriter::draft($u, $data, $job);
            Settings::audit($id, 'cover_letter_drafted', (string) ($v['scanId'] ?? ''));

            return $ok($out);
        }
        if ($path === 'ai/status' && $get) {
            return $ok(['ai' => \App\Services\TaraAI::status($u)]);
        }
        if ($path === 'ai/suggest' && $r->isMethod('POST')) {
            if (\App\Services\TaraAI::enabled()) {
                $v = $r->validate(['task' => 'required|in:'.implode(',', array_keys(\App\Services\TaraAI::TASKS)), 'text' => 'required|string|min:20|max:12000', 'context' => 'nullable|string|max:8000', 'consent' => 'accepted']);
                $key = 'tara-ai:'.$id;
                if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 6)) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['ai' => 'Please wait a moment before asking Tara again.']);
                }
                \Illuminate\Support\Facades\RateLimiter::hit($key, 60);

                return $ok(['suggestion' => \App\Services\TaraAI::suggest($u, $v), 'left' => \App\Services\TaraAI::left($u)]);
            }
            $v = $r->validate(['task' => 'required|in:rewrite,translate,review_requirements', 'language' => 'required|in:English', 'text' => 'required|string|min:30|max:12000', 'context' => 'nullable|string|max:8000', 'consent' => 'accepted']);

            return $ok(['suggestion' => WritingAssistant::suggest($u, $v)]);
        }
        if ($path === 'allowance') {
            $a = Settings::access($id);
            $l = $a['limits'];
            $pid = $a['purchase']?->id;
            $exports = $pid ? DB::table('product_usage')->where('owner_id', $id)->where('action', 'export')->where('reference', 'like', $pid.':%')->count() : 0;
            $cs = Credits::status($id);
            $scans = $a['paid'] ? $cs['scans']['used'] : DB::table('ats_scans')->where('owner_id', $id)->where('status', '!=', 'void')->count();
            $plans = Settings::plans();
            unset($a['purchase']);

            return $ok([...$a, 'emailVerified' => ! \App\Services\EmailVerification::required($r->user()), 'jobLinks' => (bool) (((array) Settings::get('speed', []))['jobLinks'] ?? false), 'planName' => $plans[$a['plan']]['name'] ?? 'Free', 'daysLeft' => $a['accessUntil'] ? max(0, (int) ceil((strtotime($a['accessUntil']) - time()) / 86400)) : 0, 'credits' => $cs, 'caps' => ['resumes' => (int) $l['savedResumes'] + 1, 'letters' => (int) $l['savedLetters'], 'atsScans' => $cs['scans']['total'], 'exports' => null], 'used' => ['exports' => $exports, 'savedResumes' => DB::table('resume_drafts')->where('owner_id', $id)->count(), 'savedLetters' => DB::table('cover_letter_drafts')->where('owner_id', $id)->count(), 'atsScans' => $scans, 'dailyMatches' => DB::table('ats_scans')->where('owner_id', $id)->where('status', '!=', 'void')->whereDate('created_at', today())->count()]]);
        }
        if ($path === 'resumes/import') {
            abort_unless($r->isMethod('POST'), 405);

            return $this->importResume($r);
        }
        if (preg_match('#^resumes/([a-f0-9-]{36})/quality$#', $path, $m) && $get) {
            $row = DB::table('resume_drafts')->where('owner_id', $id)->where('id', $m[1])->first(['content_json']);
            abort_unless($row, 404);

            return $ok(['quality' => \App\Services\ResumeQuality::score(json_decode($row->content_json, true) ?: [])]);
        }
        if ($path === 'notifications') {
            if ($r->isMethod('POST')) {
                $ids = array_filter((array) $r->input('ids', []), fn ($x) => is_string($x) && preg_match('/^[a-f0-9-]{36}$/', $x));
                DB::table('notifications')->where('owner_id', $id)->when($ids, fn ($q) => $q->whereIn('id', $ids))->whereNull('read_at')->update(['read_at' => now()]);

                return $ok();
            }

            return $ok(['notifications' => DB::table('notifications')->where('owner_id', $id)->whereNull('read_at')->orderByDesc('created_at')->limit(10)->get(['id', 'kind', 'title', 'body', 'link', 'created_at'])]);
        }
        if (preg_match('#^resumes/([a-f0-9-]{36})/tailor$#', $path, $m) && $r->isMethod('POST')) {
            // the CVTara team rewrites this resume for one checked job; the builder shows it for review, nothing is saved here
            $v = $r->validate(['scanId' => ['required', 'regex:/^[a-f0-9-]{36}$/'], 'consent' => 'accepted']);
            $row = DB::table('resume_drafts')->where('id', $m[1])->where('owner_id', $id)->first();
            abort_unless($row, 404);
            $sc = DB::table('ats_scans')->where('id', $v['scanId'])->where('owner_id', $id)->where('status', 'done')->first();
            abort_unless($sc, 422, 'That ATS check was not found.');
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('tara-ai:'.$id, 6)) {
                throw ValidationException::withMessages(['ai' => 'Please wait a moment before asking the CVTara team again.']);
            }
            \Illuminate\Support\Facades\RateLimiter::hit('tara-ai:'.$id, 60);

            return $ok(['tailored' => \App\Services\ResumeTailor::run($u, json_decode((string) $row->content_json, true) ?: [], (string) $sc->job_text, json_decode((string) $sc->result_json, true) ?: [])]);
        }
        if (preg_match('#^resumes/([a-f0-9-]{36})/(primary|variant)$#', $path, $m)) {
            abort_unless($r->isMethod('POST'), 405);

            return $this->primaryTools($r, $m[1], $m[2]);
        }
        if ($path === 'account/export') {
            return $ok(['export' => $this->accountExport($u)]);
        }
        if ($path === 'account/delete') {
            abort_unless($r->isMethod('POST'), 405);
            $r->validate(['password' => 'required|string|max:200', 'confirm' => 'required|in:DELETE']);
            abort_unless(\Illuminate\Support\Facades\Hash::check($r->password, $u->password), 422, 'The password is incorrect.');
            $this->deleteAccount($u);
            \Illuminate\Support\Facades\Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return $ok(['redirect' => '/']);
        }
        if (preg_match('#^(resumes|letters)(?:/([a-f0-9-]{36}))?$#', $path, $m)) {
            return $this->documents($r, $m[1], $m[2] ?? null);
        }
        if ($path === 'ats/scan' && $r->isMethod('POST')) {
            $v = $r->validate(['resumeId' => ['required', 'regex:/^[a-f0-9-]{36}$/'], 'source' => 'required|in:link,file,text', 'url' => 'nullable|string|max:1000', 'text' => 'nullable|string|max:60000', 'title' => 'nullable|string|max:180', 'company' => 'nullable|string|max:180']);
            abort_unless(Settings::get('features')['match']['enabled'], 403);
            \App\Services\EmailVerification::guard($r->user());
            if ($v['source'] === 'link' && ! (((array) Settings::get('speed', []))['jobLinks'] ?? false)) {
                return response()->json(['ok' => false, 'error' => 'Job links are not available yet. Paste the job text or upload a PDF.'], 422);
            }
            \App\Services\Traffic::guardHeavy();
            $resume = DB::table('resume_drafts')->where('id', $v['resumeId'])->where('owner_id', $id)->first();
            abort_unless($resume, 422, 'Choose one of your saved resumes.');
            $access = Settings::access($id);
            $today = DB::table('ats_scans')->where('owner_id', $id)->whereDate('created_at', today())->count();
            $cap = $access['paid'] ? (int) ($access['limits']['dailyMatches'] ?? 100) : min((int) Settings::get('features')['match']['limit'], (int) $access['limits']['dailyMatches']);
            if ($today >= $cap) {
                throw ValidationException::withMessages(['limit' => $access['paid'] ? 'Daily ATS check limit reached. Try again tomorrow.' : 'Your free ATS checks for today are used. Try again tomorrow or choose a plan for more scans.']);
            }
            if (! Credits::scanAvailable($id)) {
                throw ValidationException::withMessages(['limit' => Credits::outOf('ATS scans')]);
            }
            $title = trim((string) ($v['title'] ?? ''));
            $company = trim((string) ($v['company'] ?? ''));
            $details = [];
            if ($v['source'] === 'link') {
                if (empty($v['url'])) {
                    throw ValidationException::withMessages(['url' => 'Paste the link of the job posting.']);
                }
                if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('ats-link:'.$id, (int) env('ATS_LINK_RATE', 10))) {
                    throw ValidationException::withMessages(['url' => 'Too many job links in a minute. Wait a moment and try again.']);
                }
                \Illuminate\Support\Facades\RateLimiter::hit('ats-link:'.$id, 60);
                $page = JobPage::fetch($v['url']);
                $text = $page['text'];
                $title = $title ?: $page['title'];
                $company = $company ?: $page['company'];
                $details = $page['details'] ?? [];
            } else {
                $text = mb_substr(trim((string) ($v['text'] ?? '')), 0, 40000);
                if (mb_strlen($text) < 80) {
                    throw ValidationException::withMessages(['text' => 'The job description is too short to review. Include the responsibilities and requirements.']);
                }
            }
            if ($title === '' || $company === '') {
                [$guessTitle, $guessCompany] = \App\Services\AtsEngine::headline($text);
                $title = $title ?: $guessTitle;
                $company = $company ?: $guessCompany;
            }
            try {
                $report = \App\Services\AtsEngine::report(json_decode((string) $resume->content_json, true) ?: [], $text, $title);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['text' => $e->getMessage()]);
            }
            $report['details'] = $details;
            $resumeData = json_decode((string) $resume->content_json, true) ?: [];
            $report['resumeHash'] = \App\Services\AtsEngine::fingerprint($resumeData);
            $scanId = (string) Str::uuid();
            DB::transaction(function () use ($id, $scanId, $v, $text, $title, $company, $report) {
                User::whereKey($id)->lockForUpdate()->first();
                Credits::reserveScan($id, $scanId);
                DB::table('ats_scans')->insert(['id' => $scanId, 'owner_id' => $id, 'resume_id' => $v['resumeId'], 'source' => $v['source'], 'job_url' => $v['source'] === 'link' ? mb_substr(JobPage::canonical((string) $v['url']), 0, 1000) : '', 'job_title' => $title, 'company' => $company, 'job_text' => $text, 'score' => $report['score'], 'result_json' => json_encode($report), 'status' => 'done', 'created_at' => now(), 'updated_at' => now()]);
            });
            Settings::audit($id, 'resume_compared');

            return $ok(['scanId' => $scanId, 'title' => $title, 'company' => $company, 'jobUrl' => $v['source'] === 'link' ? JobPage::canonical((string) $v['url']) : '', 'report' => $report, 'resumeTitle' => $resume->title, 'sameContent' => $this->sameContent($id, $resume, $report['resumeHash'], $text, $scanId), 'credits' => Credits::status($id)]);
        }
        if ($path === 'ats/scans' && $get) {
            $rows = DB::table('ats_scans')->where('ats_scans.owner_id', $id)->where('ats_scans.status', 'done')->leftJoin('resume_drafts', 'resume_drafts.id', '=', 'ats_scans.resume_id')->orderByDesc('ats_scans.created_at')->limit(30)->get(['ats_scans.id', 'ats_scans.resume_id', 'ats_scans.source', 'ats_scans.job_url', 'ats_scans.job_title', 'ats_scans.company', 'ats_scans.score', 'ats_scans.created_at', 'resume_drafts.title as resume_title']);

            return $ok(['scans' => $rows->map(fn ($x) => ['id' => $x->id, 'resumeId' => $x->resume_id, 'resumeTitle' => $x->resume_title, 'source' => $x->source, 'jobUrl' => $x->job_url, 'title' => $x->job_title, 'company' => $x->company, 'score' => $x->score, 'createdAt' => $x->created_at]), 'credits' => Credits::status($id)]);
        }
        if (preg_match('#^ats/scans/([a-f0-9-]{36})$#', $path, $m) && $get) {
            $x = DB::table('ats_scans')->where('id', $m[1])->where('owner_id', $id)->where('status', 'done')->first();
            abort_unless($x, 404);

            return $ok(['scan' => ['id' => $x->id, 'resumeId' => $x->resume_id, 'resumeTitle' => DB::table('resume_drafts')->where('id', $x->resume_id)->where('owner_id', $id)->value('title'), 'source' => $x->source, 'jobUrl' => $x->job_url, 'jobText' => $x->job_text, 'title' => $x->job_title, 'company' => $x->company, 'score' => $x->score, 'report' => json_decode((string) $x->result_json, true), 'createdAt' => $x->created_at]]);
        }
        if ($path === 'applications' && $get) {
            return $ok(['applications' => DB::table('applications')->where('owner_id', $id)->orderByDesc('updated_at')->get()]);
        }
        if ($path === 'applications' && $r->isMethod('POST')) {
            $v = $r->validate(['company' => 'required|string|max:180', 'role' => 'required|string|max:180', 'stage' => 'required|in:saved,applied,interview,offer,closed', 'jobUrl' => 'nullable|url:http,https|max:1000', 'appliedAt' => 'nullable|date_format:Y-m-d', 'followUp' => 'nullable|date_format:Y-m-d', 'resumeId' => 'nullable|uuid', 'notes' => 'nullable|string|max:8000', 'id' => 'nullable|uuid', 'revision' => 'nullable|integer', 'remindersOff' => 'nullable|boolean']);
            $result = DB::transaction(function () use ($v, $id) {
                User::whereKey($id)->lockForUpdate()->first();
                $feature = Settings::get('features')['tracker'];
                abort_unless($feature['enabled'], 403);
                if (! empty($v['resumeId'])) {
                    abort_unless(DB::table('resume_drafts')->where('owner_id', $id)->where('id', $v['resumeId'])->exists(), 422);
                }$record = ['owner_id' => $id, 'company' => $v['company'], 'role' => $v['role'], 'stage' => $v['stage'], 'job_url' => $v['jobUrl'] ?? '', 'applied_at' => $v['appliedAt'] ?? null, 'follow_up' => $v['followUp'] ?? null, 'resume_id' => $v['resumeId'] ?? null, 'notes' => $v['notes'] ?? '', 'updated_at' => now()];
                if (array_key_exists('remindersOff', $v) && $v['remindersOff'] !== null) {
                    $record['reminders_off'] = (bool) $v['remindersOff'];
                }
                if (in_array($v['stage'], ['offer', 'closed'], true)) {
                    $record['next_reminder_at'] = null;
                }
                $aid = $v['id'] ?? (string) Str::uuid();
                if (! empty($v['id'])) {
                    abort_unless(DB::table('applications')->where('id', $aid)->where('owner_id', $id)->where('revision', $v['revision'] ?? 0)->update([...$record, 'revision' => DB::raw('revision+1')]), 409);
                } else {
                    abort_if(DB::table('applications')->where('owner_id', $id)->count() >= $feature['limit'], 422);
                    DB::table('applications')->insert([...$record, 'id' => $aid, 'revision' => 1, 'created_at' => now()]);
                }

                return $aid;
            });

            return $ok(['id' => $result]);
        }
        if (preg_match('#^applications/([a-f0-9-]{36})$#', $path, $m) && $r->isMethod('DELETE')) {
            abort_unless(DB::table('applications')->where('id', $m[1])->where('owner_id', $id)->where('revision', $r->input('revision'))->delete(), 409);

            return $ok();
        }
        if ($path === 'purchases') {
            return $ok(['purchases' => \App\Services\Refunds::forUser($id), 'services' => \App\Services\Services::forUser($id), 'refundDays' => \App\Services\Refunds::DAYS]);
        }
        if ($path === 'refunds') {
            abort_unless($r->isMethod('POST'), 405);
            $v = $r->validate(['purchaseId' => 'required|uuid', 'reason' => 'nullable|string|max:1000']);
            $key = 'refund-try:'.$id;
            abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Wait a few minutes.');
            \Illuminate\Support\Facades\RateLimiter::hit($key, 600);

            return $ok(['refund' => \App\Services\Refunds::request($u, $v['purchaseId'], (string) ($v['reason'] ?? ''))]);
        }
        if ($path === 'wallet' && $get) {
            return $ok(\App\Services\Wallet::summary($id));
        }
        if ($path === 'checkout/quote') {
            abort_unless($r->isMethod('POST'), 405);
            $v = $r->validate(['plan' => 'required|in:'.implode(',', Settings::paidPlanKeys()), 'coupon' => 'nullable|string|max:30', 'useWallet' => 'nullable|boolean']);
            Payments::guardAddon($u, $v['plan']);
            if (! empty($v['coupon'])) {
                $rk = 'coupon-try:'.$u->id;
                abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rk, 10), 429, 'Too many coupon attempts. Wait a minute.');
                \Illuminate\Support\Facades\RateLimiter::hit($rk, 60);
            }

            return $ok(BusinessControls::quote($v['plan'], $v['coupon'] ?? '', $u->id, $r->boolean('useWallet', true)));
        }
        if ($path === 'checkout/start') {
            \App\Services\EmailVerification::guard($r->user());
            $r->validate(['plan' => 'required|in:'.implode(',', Settings::paidPlanKeys()), 'showInActivity' => 'nullable|boolean', 'useWallet' => 'nullable|boolean']);
            if ($r->has('showInActivity')) {
                DB::table('profiles')->where('owner_id', $id)->update(['show_in_activity' => $r->boolean('showInActivity')]);
            }
            Payments::guardAddon($u, $r->plan);

            return $ok(Payments::start($u, $r->plan, (string) $r->input('coupon', ''), $r->boolean('useWallet', true)));
        }
        if ($path === 'checkout/verify' && config('app.preview') && $r->input('provider') === 'demo') {
            $r->validate(['orderId' => 'required|string|max:80']);
            Payments::demoVerify($u, $r->orderId);

            return $ok();
        }
        if ($path === 'checkout/verify') {
            $v = $r->validate(['razorpay_order_id' => 'required|string|max:100', 'razorpay_payment_id' => 'required|string|max:100', 'razorpay_signature' => 'required|string|size:64']);
            Payments::verify($u, $v);

            return $ok();
        }
        if ($path === 'referrals' && $get) {
            if (! $u->referral_code) {
                $u->forceFill(['referral_code' => Referrals::makeCode((string) $u->name)])->save();
            }

            $referredUsers = User::where('referred_by', $id)
                ->select('id', 'created_at', 'email_verified_at', 'blocked')
                ->selectSub(DB::table('purchases')->selectRaw('COUNT(*)')->whereColumn('owner_id', 'users.id')->where('status', 'paid')->where('is_live', true)->whereIn('provider', ['razorpay', 'cashfree'])->where('amount_paise', '>=', Referrals::config()['minQualifyingPaise'])->where('refunded_paise', 0)->whereNotNull('payment_reference')->where('plan', '!=', 'free'), 'qualifying_payments')
                ->selectSub(DB::table('referral_identities')->selectRaw('COUNT(*)')->whereColumn('owner_id', 'users.id')->where('status', 'verified'), 'verified_phone')
                ->orderByDesc('created_at')->limit(200)->get()->map(fn ($friend) => [
                    'label' => 'Friend '.substr(hash_hmac('sha256', (string) $friend->id, config('app.key')), 0, 6),
                    'joinedAt' => $friend->created_at->toIso8601String(),
                    'status' => $friend->blocked ? 'Under review' : (! $friend->email_verified_at || ! $friend->verified_phone ? 'Verification pending' : ($friend->qualifying_payments ? 'Qualifying paid referral' : 'Payment pending')),
                ]);

            return $ok(['connected' => true, 'mode' => Referrals::config()['mode'], 'wallet' => \App\Services\Wallet::summary($id), 'commissionEligible' => ! Referrals::config()['proOnly'] || (bool) (Settings::access($id)['limits']['referral'] ?? false), 'referredUsers' => $referredUsers, 'rules' => Referrals::config(), 'effectiveRateBps' => Referrals::rate($id), 'successfulBuyers' => Referrals::successfulBuyers($id), 'creator' => DB::table('creator_applications')->where('owner_id', $id)->first(), 'payoutProfile' => AffiliateProfiles::publicProfile($id), 'emailVerified' => (bool) $u->email_verified_at, 'code' => $u->referral_code, 'url' => url('/r/'.$u->referral_code), 'balance' => Referrals::balance($id), 'clicks' => DB::table('referral_clicks')->where('referrer_id', $id)->count(), 'signups' => User::where('referred_by', $id)->count(), 'commissions' => DB::table('referral_commissions')->where('referrer_id', $id)->orderByDesc('created_at')->limit(200)->get(['id', 'purchase_id', 'amount_paise', 'reversed_paise', 'rate_bps', 'available_at', 'created_at']), 'withdrawals' => DB::table('withdrawals')->where('owner_id', $id)->orderByDesc('created_at')->limit(100)->get(['id', 'amount_paise', 'method', 'destination_masked', 'status', 'reference', 'note', 'due_at', 'paid_at', 'created_at'])]);
        }
        if ($path === 'referrals/withdraw' && $r->isMethod('POST')) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['amountPaise' => 'required|integer|min:'.max(100, (int) Referrals::config()['minimumPaise']).'|max:100000000']);

            return $ok(['id' => Referrals::request($u, $v)]);
        }
        if ($path === 'feedback' && $get) {
            return $ok(['reviews' => DB::table('feedback')->where('owner_id', $id)->orderByDesc('created_at')->limit(10)->get()]);
        }
        if ($path === 'feedback' && $r->isMethod('POST')) {
            $v = $r->validate(['displayName' => 'required|string|max:100', 'rating' => 'required|integer|min:1|max:5', 'content' => 'required|string|min:10|max:2000', 'consent' => 'required|boolean']);
            abort_if(DB::table('feedback')->where('owner_id', $id)->count() >= 10, 422);
            DB::table('feedback')->insert(['id' => (string) Str::uuid(), 'owner_id' => $id, 'display_name' => $v['displayName'], 'rating' => $v['rating'], 'content' => $v['content'], 'consent' => $v['consent'], 'consented_at' => $v['consent'] ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

            return $ok();
        }
        if (preg_match('#^feedback/([a-f0-9-]{36})/withdraw$#', $path, $m)) {
            DB::table('feedback')->where('owner_id', $id)->where('id', $m[1])->update(['consent' => false, 'status' => 'hidden', 'updated_at' => now()]);

            return $ok();
        }

        return response()->json(['ok' => false, 'error' => 'This action is not available.'], 404);
    }

    private function primaryTools(Request $r, string $docId, string $action)
    {
        $u = $r->user();
        $id = $u->id;
        $row = DB::table('resume_drafts')->where('id', $docId)->where('owner_id', $id)->first();
        abort_unless($row, 404);
        if ($action === 'primary') {
            DB::transaction(function () use ($id, $docId) {
                User::whereKey($id)->lockForUpdate()->first();
                DB::table('resume_drafts')->where('owner_id', $id)->update(['is_primary' => false]);
                DB::table('resume_drafts')->where('id', $docId)->update(['is_primary' => true, 'parent_id' => null]);
            });
            Settings::audit($id, 'primary_resume_set', $docId);

            return response()->json(['ok' => true]);
        }
        $v = $r->validate(['company' => 'required|string|max:160', 'role' => 'required|string|max:160', 'jobDescription' => 'nullable|string|max:40000', 'jobUrl' => 'nullable|url:http,https|max:1000', 'scanId' => ['nullable', 'regex:/^[a-f0-9-]{36}$/']]);
        if (! empty($v['scanId'])) {
            $sc = DB::table('ats_scans')->where('id', $v['scanId'])->where('owner_id', $id)->where('status', 'done')->first();
            abort_unless($sc, 422, 'That ATS check was not found.');
            $v['jobDescription'] = $sc->job_text;
            $v['jobUrl'] = $sc->job_url ?: null;
        }
        $new = DB::transaction(function () use ($id, $row, $v) {
            User::whereKey($id)->lockForUpdate()->first();
            abort_unless(Settings::get('features')['builder']['enabled'], 403);
            $acc = Settings::access($id);
            if (! $acc['paid']) {
                throw ValidationException::withMessages(['limit' => 'A free account keeps one resume. Choose a plan to create job-tailored versions and download.']);
            }
            if (! empty($acc['limits']['oneResume'])) {
                throw ValidationException::withMessages(['limit' => 'Quick Download covers one resume. Job Hunt adds 5 tailored resumes and 5 cover letters, one for each job.']);
            }
            if (DB::table('resume_drafts')->where('owner_id', $id)->count() >= Settings::get('features')['builder']['limit']) {
                throw ValidationException::withMessages(['limit' => 'You have reached the saved resume limit for your plan. Edit an existing resume, or choose a plan with more room.']);
            }
            $data = json_decode($row->content_json, true);
            unset($data['_profileId'], $data['_profileRevision']);
            $data['title'] = $v['role'].' · '.$v['company'];
            $data['company'] = $v['company'];
            $data['role'] = $v['role'];
            $nid = (string) Str::uuid();
            DB::table('resume_drafts')->insert(['id' => $nid, 'owner_id' => $id, 'title' => substr($data['title'], 0, 180), 'template' => $row->template, 'company' => $v['company'], 'role' => $v['role'], 'content_json' => json_encode($data), 'revision' => 1, 'is_primary' => false, 'parent_id' => $row->id, 'job_description' => $v['jobDescription'] ?? null, 'job_url' => $v['jobUrl'] ?? null, 'ats_score' => \App\Services\ResumeQuality::score($data)['score'], 'source' => 'tailored', 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($id, 'resume_variant_created', $nid);

            return $nid;
        });

        // A resume prepared for a job goes straight into the Applications tracker.
        $tracked = \App\Services\Tracker::prepared($id, $v['company'], $v['role'], (string) ($v['jobUrl'] ?? ''), $v['scanId'] ?? null, $new);

        return response()->json(['ok' => true, 'id' => $new, 'revision' => 1, 'tracker' => $tracked]);
    }

    /** Builds a resume from an uploaded file's text (already extracted in the browser) and saves it as a normal draft. */
    private function importResume(Request $r)
    {
        $v = $r->validate(['text' => 'required|string|min:60|max:120000', 'fileName' => 'nullable|string|max:200', 'template' => 'nullable|string|max:120', 'paragraphs' => 'nullable|boolean', 'replaceId' => ['nullable', 'regex:/^[a-f0-9-]{36}$/']]);
        $uid = $r->user()->id;
        $replace = ! empty($v['replaceId']) ? DB::table('resume_drafts')->where('id', $v['replaceId'])->where('owner_id', $uid)->first(['id', 'revision']) : null;
        abort_if(! empty($v['replaceId']) && ! $replace, 404);
        // No room for another resume on this plan: offer to put the upload into one the customer already has, instead of a dead end.
        $count = DB::table('resume_drafts')->where('owner_id', $uid)->count();
        $paid = Settings::access($uid)['paid'];
        if (! $replace && $count >= ($paid ? Settings::get('features')['builder']['limit'] : 1)) {
            $mine = DB::table('resume_drafts')->where('owner_id', $uid)->orderByDesc('is_primary')->orderByDesc('updated_at')->limit(20)->get(['id', 'title', 'updated_at'])->map(fn ($d) => ['id' => $d->id, 'title' => $d->title ?: 'Untitled resume', 'updatedAt' => $d->updated_at]);

            return response()->json(['ok' => false, 'needsReplace' => true, 'paid' => $paid, 'resumes' => $mine, 'error' => $paid ? 'Your plan\'s saved resumes are full. Choose a resume to replace with this file.' : 'Your free account keeps one resume. You can put this file into it, or choose a plan to keep both.'], 409);
        }
        $catalog = json_decode(file_get_contents(resource_path('data/catalog.json')), true)['templates'] ?? [];
        $lib = Settings::get('library')['templates'] ?? [];
        $ok = fn ($slug) => collect($catalog)->contains('slug', $slug) && ($lib[$slug]['published'] ?? true) !== false;
        // An uploaded resume starts in a plain, ATS-conscious layout (no photo, one column); the customer can change it in the builder.
        $slug = ! empty($v['template']) && $ok($v['template']) ? $v['template'] : (collect($catalog)->first(fn ($t) => $ok($t['slug']) && ! empty($t['ats']) && empty($t['photo']) && empty($t['creative']))['slug'] ?? collect($catalog)->first(fn ($t) => $ok($t['slug']))['slug'] ?? null);
        abort_unless($slug, 422, 'No resume template is available right now.');
        $parsed = \App\Services\ResumeImport::parse($v['text'], $slug, (string) ($v['fileName'] ?? ''), (bool) ($v['paragraphs'] ?? false));
        $data = $parsed['data'];
        if (trim($data['personal']['name']) === '') {
            $data['personal']['name'] = 'Your name';
        }
        $r->merge(['id' => $replace?->id, 'revision' => $replace?->revision, 'data' => $data]);
        $saved = $this->documents($r, 'resumes', $replace?->id)->getData(true);
        DB::table('resume_drafts')->where('id', $saved['id'])->where('owner_id', $r->user()->id)->update(['source' => 'import', 'content_json' => DB::raw("REPLACE(content_json, '\"sectionLabels\":[]', '\"sectionLabels\":{}')")]);
        $q = \App\Services\ResumeQuality::score($data);

        return response()->json(['ok' => true, 'id' => $saved['id'], 'revision' => $saved['revision'], 'found' => $parsed['found'], 'warnings' => $parsed['warnings'], 'quality' => ['score' => $q['score'], 'tips' => $q['tips']]]);
    }

    private function accountExport(User $u): array
    {
        $id = $u->id;
        $rows = fn ($t, $cols = ['*']) => DB::table($t)->where('owner_id', $id)->get($cols)->map(fn ($x) => (array) $x)->all();
        $decode = fn (array $list) => array_map(function ($x) {
            $x['content'] = json_decode($x['content_json'] ?? 'null', true);
            unset($x['content_json']);

            return $x;
        }, $list);

        return ['generatedAt' => now()->toIso8601String(), 'account' => ['name' => $u->name, 'email' => $u->email, 'createdAt' => $u->created_at, 'referralCode' => $u->referral_code], 'profile' => (array) DB::table('profiles')->where('owner_id', $id)->first(), 'resumes' => $decode($rows('resume_drafts')), 'coverLetters' => $decode($rows('cover_letter_drafts')), 'applications' => $rows('applications'), 'purchases' => $rows('purchases', ['id', 'plan', 'amount_paise', 'refunded_paise', 'currency', 'status', 'coupon_code', 'purchased_at', 'access_until']), 'reviews' => $rows('feedback', ['display_name', 'rating', 'content', 'status', 'created_at']), 'referralCommissions' => DB::table('referral_commissions')->where('referrer_id', $id)->get(['amount_paise', 'rate_bps', 'created_at'])->map(fn ($x) => (array) $x)->all(), 'withdrawals' => $rows('withdrawals', ['amount_paise', 'destination_masked', 'status', 'reference', 'created_at']), 'payoutProfile' => (array) DB::table('payout_profiles')->where('owner_id', $id)->first(['status', 'destination_masked']), 'creatorApplication' => (array) DB::table('creator_applications')->where('owner_id', $id)->first(['status', 'audience', 'rate_bps', 'created_at']), 'refundRequests' => $rows('refund_requests', ['purchase_id', 'status', 'amount_paise', 'reason', 'note', 'created_at']), 'walletTransactions' => $rows('wallet_transactions', ['kind', 'amount_paise', 'note', 'earned_at', 'expires_at', 'created_at']), 'serviceOrders' => $rows('service_orders', ['service', 'status', 'note', 'created_at', 'delivered_at']), 'atsChecks' => $rows('ats_scans', ['id', 'source', 'job_url', 'job_title', 'company', 'score', 'result_json', 'status', 'created_at']), 'activity' => DB::table('activity_events')->where('owner_id', $id)->orderByDesc('created_at')->limit(500)->get(['event', 'reference', 'created_at'])->map(fn ($x) => (array) $x)->all()];
    }

    // The account and everything in it is removed for good. Money records stay for accounting, detached from the person (AccountEraser).
    public function deleteAccount(User $u): void
    {
        abort_if($u->is_admin, 422, 'The owner account cannot be deleted here.');
        abort_if(DB::table('withdrawals')->where('owner_id', $u->id)->whereIn('status', ['requested', 'processing'])->exists(), 422, 'Finish or cancel your open withdrawal first.');
        \App\Services\AccountEraser::erase($u->id);
    }

    /**
     * Why two checks can give the same score: this resume has exactly the same text as another resume already checked
     * against this job, or it is a tailored copy that has not been changed since it was copied.
     */
    private function sameContent(string $owner, object $resume, string $hash, string $jobText, string $scanId): ?array
    {
        if (! empty($resume->parent_id)) {
            $parent = DB::table('resume_drafts')->where('id', $resume->parent_id)->where('owner_id', $owner)->first(['title', 'content_json']);
            if ($parent && \App\Services\AtsEngine::fingerprint(json_decode((string) $parent->content_json, true) ?: []) === $hash) {
                return ['kind' => 'unchanged_copy', 'title' => $parent->title];
            }
        }
        $earlier = DB::table('ats_scans')->where('ats_scans.owner_id', $owner)->where('ats_scans.status', 'done')->where('ats_scans.id', '!=', $scanId)->where('ats_scans.resume_id', '!=', $resume->id)
            ->where('ats_scans.job_text', $jobText)->join('resume_drafts', 'resume_drafts.id', '=', 'ats_scans.resume_id')->orderByDesc('ats_scans.created_at')->limit(10)->get(['ats_scans.result_json', 'resume_drafts.title', 'resume_drafts.content_json']);
        foreach ($earlier as $e) {
            if (\App\Services\AtsEngine::fingerprint(json_decode((string) $e->content_json, true) ?: []) === $hash) {
                return ['kind' => 'same_text', 'title' => $e->title];
            }
        }

        return null;
    }

    private function documents(Request $r, string $type, ?string $doc)
    {
        $u = $r->user();
        $id = $u->id;
        $table = $type === 'resumes' ? 'resume_drafts' : 'cover_letter_drafts';
        $one = $type === 'resumes' ? 'resume' : 'letter';
        $acc = Settings::access($id)['limits'];
        $limit = $type === 'resumes' ? (int) Settings::get('features')['builder']['limit'] : (int) (Settings::get('features')['letter']['draftLimit'] ?? 500);
        $query = DB::table($table)->where('owner_id', $id);
        if ($r->isMethod('GET')) {
            if ($doc) {
                $row = (clone $query)->where('id', $doc)->first();
                abort_unless($row, 404);

                return response()->json(['ok' => true, $one => ['id' => $row->id, 'revision' => $row->revision, 'isPrimary' => (bool) ($row->is_primary ?? false), 'parentId' => $row->parent_id ?? null, 'jobDescription' => $row->job_description ?? '', 'jobUrl' => $row->job_url ?? '', 'data' => json_decode($row->content_json, true)]]);
            }

            $rows = $query->orderByDesc('updated_at')->get($type === 'resumes' ? ['id', 'title', 'template', 'company', 'role', 'revision', 'is_primary', 'parent_id', 'ats_score', 'downloaded_at', 'source', 'created_at', 'updated_at'] : ['id', 'title', 'template', 'company', 'role', 'revision', 'downloaded_at', 'created_at', 'updated_at']);
            if ($type === 'resumes') {
                // Resumes saved before the score existed are scored the first time they are listed.
                foreach ($rows->filter(fn ($x) => $x->ats_score === null)->take(40) as $x) {
                    $c = DB::table('resume_drafts')->where('id', $x->id)->value('content_json');
                    $x->ats_score = \App\Services\ResumeQuality::score(json_decode((string) $c, true) ?: [])['score'];
                    DB::table('resume_drafts')->where('id', $x->id)->update(['ats_score' => $x->ats_score]);
                }
                // Best job match each resume has reached, and the latest one.
                $scans = DB::table('ats_scans')->where('owner_id', $id)->where('status', 'done')->whereNotNull('score')->whereIn('resume_id', $rows->pluck('id'))->orderByDesc('created_at')->get(['resume_id', 'score', 'job_title', 'company', 'created_at'])->groupBy('resume_id');
                $order = $rows->sortBy('created_at')->pluck('id')->values()->all();
                $rows = $rows->map(function ($x) use ($scans, $order) {
                    $x = (array) $x;
                    $s = $scans[$x['id']] ?? collect();
                    $last = $s->first();
                    $x['match'] = $last ? ['score' => (int) $last->score, 'company' => $last->company, 'role' => $last->job_title, 'best' => (int) $s->max('score'), 'count' => $s->count()] : null;
                    $x['locked'] = true;
                    $x['number'] = array_search($x['id'], $order, true) + 1;

                    return $x;
                })->values();
            }

            return response()->json(['ok' => true, $type => $rows, 'limit' => $limit, 'canDelete' => $type !== 'resumes' || (bool) (Settings::get('features')['builder']['allowDelete'] ?? false), 'credits' => Credits::status($id)]);
        }
        if ($r->isMethod('DELETE')) {
            $existing = $doc ? (clone $query)->where('id', $doc)->first() : null;
            abort_unless($existing, 404);
            if ($type === 'resumes' && ! (Settings::get('features')['builder']['allowDelete'] ?? false)) {
                abort(422, 'Saved resumes stay in your account so your download credits stay accurate. Edit this resume instead, or contact support if you need it removed.');
            }
            abort_if(! empty($existing->downloaded_at), 422, 'This '.$one.' has been downloaded, so it stays in your account. You can still edit it.');
            abort_unless((clone $query)->where('id', $doc)->delete(), 404);
            if ($type === 'resumes' && ! DB::table('resume_drafts')->where('owner_id', $id)->where('is_primary', true)->exists()) {
                $next = DB::table('resume_drafts')->where('owner_id', $id)->orderBy('created_at')->value('id');
                if ($next) {
                    DB::table('resume_drafts')->where('id', $next)->update(['is_primary' => true, 'parent_id' => null]);
                }
            }
            Settings::audit($id, $one.'_deleted', $doc);

            return response()->json(['ok' => true]);
        }
        $r->validate(['data' => 'required|array', 'data.version' => 'required|in:1', 'data.title' => 'required|string|max:180', 'data.template' => 'required|string|max:120', 'data.personal' => 'required|array', 'data.personal.name' => 'required|string|max:300', 'data.accent' => 'required|regex:/^#[0-9a-fA-F]{6}$/', 'data.font' => 'required|in:Arial,Georgia,Verdana', 'id' => 'nullable|uuid', 'revision' => 'nullable|integer']);
        $rules = ['data.personal.*' => 'nullable|string|max:1000', 'data.hidden' => 'sometimes|array|max:12', 'data.hidden.*' => 'string|max:30', 'data.order.*' => 'string|in:summary,experience,education,projects,skills,certifications,languages,awards', 'data.sectionLabels' => 'sometimes|array|max:12', 'data.sectionLabels.*' => 'nullable|string|max:80', 'data.documentLanguage' => 'sometimes|in:en'];
        foreach (['summary', 'skills', 'languages', 'awards', 'opening', 'evidence', 'motivation', 'closing', 'date', 'recipient', 'subject', 'salutation', 'signoff'] as $field) {
            $rules['data.'.$field] = 'sometimes|nullable|string|max:12000';
        }foreach (['experience', 'education', 'projects', 'certifications'] as $section) {
            $rules['data.'.$section.'.*'] = 'array';
            foreach (['role', 'degree', 'name', 'company', 'school', 'issuer', 'city', 'tools', 'link', 'start', 'end', 'date', 'bullets', 'details', 'id'] as $field) {
                $rules['data.'.$section.'.*.'.$field] = 'sometimes|nullable|string|max:12000';
            }$rules['data.'.$section.'.*.current'] = 'sometimes|boolean';
        }$r->validate($rules);
        $data = $r->input('data');
        abort_if(strlen(json_encode($data)) > 450000, 413);
        if ($type === 'resumes') {
            $r->validate(['data.order' => 'required|array|max:12', 'data.experience' => 'present|array|max:50', 'data.education' => 'present|array|max:50', 'data.projects' => 'present|array|max:50', 'data.certifications' => 'present|array|max:50', 'data.photo' => 'nullable|string|max:200000']);
            if (! empty($data['photo'])) {
                abort_unless(preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $data['photo']), 422);
                $bytes = base64_decode(explode(',', $data['photo'], 2)[1], true);
                $im = $bytes ? @getimagesizefromstring($bytes) : false;
                abort_unless($im && $im[0] <= 1024 && $im[1] <= 1024, 422);
            }
        } else {
            $r->validate(['data.company' => 'required|string|max:160', 'data.role' => 'required|string|max:160', 'data.opening' => 'required|string|max:8000', 'data.evidence' => 'required|string|max:12000']);
        }
        unset($data['_profileId'],$data['_profileRevision']);
        $result = DB::transaction(function () use ($r, $table, $id, $data, $limit, $one, $type) {
            User::whereKey($id)->lockForUpdate()->first();
            abort_unless(Settings::get('features')[$type === 'resumes' ? 'builder' : 'letter']['enabled'], 403);
            $slug = $data['template'];
            $already = $r->input('id') && DB::table($table)->where('id', $r->input('id'))->where('owner_id', $id)->where('template', $slug)->exists();
            abort_if(! $already && (Settings::get('library')[$type === 'resumes' ? 'templates' : 'letters'][$slug]['published'] ?? true) === false, 422, 'This template is no longer available. Choose another template.');
            $catalog = json_decode(file_get_contents(resource_path('data/catalog.json')), true);
            abort_unless(collect($catalog[$type === 'resumes' ? 'templates' : 'coverLetters'])->contains('slug', $slug), 422);
            $record = ['title' => $data['title'], 'template' => $slug, 'content_json' => json_encode($data), 'company' => $data['company'] ?? '', 'role' => $data['role'] ?? '', 'updated_at' => now()];
            if ($type === 'resumes') {
                $record['ats_score'] = \App\Services\ResumeQuality::score($data)['score'];
            }
            if ($r->input('id')) {
                $did = $r->input('id');
                $rev = (int) $r->input('revision');
                abort_unless(DB::table($table)->where('id', $did)->where('owner_id', $id)->where('revision', $rev)->update([...$record, 'revision' => $rev + 1]), 409);
                $rev++;
            } else {
                if (! Settings::access($id)['paid'] && DB::table($table)->where('owner_id', $id)->count() >= 1) {
                    throw ValidationException::withMessages(['limit' => 'A free account keeps one '.($type === 'resumes' ? 'resume' : 'cover letter').'. Choose a plan to create more and to download.']);
                }
                if (DB::table($table)->where('owner_id', $id)->count() >= $limit) {
                    throw ValidationException::withMessages(['limit' => 'You have reached the saved '.($type === 'resumes' ? 'resume' : 'cover letter').' limit for your plan. Open an existing one to edit it, or choose a plan with more room.']);
                }$did = (string) Str::uuid();
                $rev = 1;
                DB::table($table)->insert([...$record, 'id' => $did, 'owner_id' => $id, 'revision' => 1, 'created_at' => now(), ...($type === 'resumes' ? ['is_primary' => ! DB::table('resume_drafts')->where('owner_id', $id)->exists()] : [])]);
            }Settings::audit($id, $one.'_saved', $did);

            return ['id' => $did, 'revision' => $rev, ...($type === 'resumes' ? ['ats' => ['score' => $record['ats_score'], 'tips' => array_slice(\App\Services\ResumeQuality::score($data)['tips'], 0, 3)]] : [])];
        });
        if ($type === 'resumes') {
            MailAutomation::queue($u, 'resume_saved', 'w'.(int) floor(time() / (14 * 86400)));
        }

        return response()->json(['ok' => true, ...$result]);
    }
}
