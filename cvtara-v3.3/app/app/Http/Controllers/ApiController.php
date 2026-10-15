<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\AffiliateProfiles;
use App\Services\BusinessControls;
use App\Services\CashfreeCheckout;
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
        if ($get && preg_match('#^(checkout/|features/use|traffic$|security/(send|verify)-code|referrals/(withdraw|creator|payout-profile)$|account/delete$|feedback/[^/]+/withdraw$)#', $path)) {
            abort(405);
        }if ($r->isMethod('DELETE') && ! preg_match('#^(resumes|letters|applications)/[a-f0-9-]{36}$#', $path)) {
            abort(405);
        }$d = $r->all();
        $ok = fn ($a = []) => response()->json(['ok' => true, ...$a]);
        if ($path === 'health') {
            return $ok(['storage' => DB::connection()->getPdo() ? 'available' : 'unavailable']);
        }
        if ($path === 'business-public' && $get) {
            $d = BusinessControls::get();

            return $ok(['brand' => $d['brand'], 'growth' => $d['growth'], 'plans' => Settings::plans()]);
        }
        if ($path === 'public-config') {
            return $ok(['settings' => array_merge(['heroTitle' => 'Your next resume starts with your real experience.', 'heroDescription' => 'Choose a design. Tell your story. Prepare a focused application.', 'primaryCTA' => 'Build my resume', 'secondaryCTA' => 'Check my resume', 'accent' => '#1557d2', 'pushEnabled' => false], Settings::get('public', []), ['oneSignalAppId' => Settings::providers()['push']['oneSignalAppId'] ?? Settings::get('public', [])['oneSignalAppId'] ?? '', 'pushEnabled' => (Settings::get('integrations')['push'] ?? false) && (Settings::get('public', [])['pushEnabled'] ?? false)])]);
        }
        if ($path === 'public-activity') {
            $cfg = array_merge(['enabled' => true, 'delaySeconds' => 40, 'showSeconds' => 7, 'gapSeconds' => 10, 'useLiveActivity' => true, 'custom' => []], (array) Settings::get('popup', []));
            $messages = \Illuminate\Support\Facades\Cache::remember('public-activity', 60, function () use ($cfg) {
                $out = [];
                foreach ($cfg['custom'] as $m) {
                    if (! empty($m['enabled']) && ! empty($m['text'])) {
                        $out[] = ['icon' => $m['icon'] ?? '✨', 'title' => $m['title'] ?? 'CVTara', 'text' => $m['text']];
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

            return $ok(['settings' => ['enabled' => (bool) $cfg['enabled'], 'delaySeconds' => (int) $cfg['delaySeconds'], 'showSeconds' => (int) $cfg['showSeconds'], 'gapSeconds' => (int) $cfg['gapSeconds']], 'messages' => $messages]);
        }
        if ($path === 'public-content') {
            $content = [];
            foreach (['legal', 'features', 'library', 'blog', 'integrations', 'seo', 'site'] as $k) {
                $content[$k] = Settings::get($k);
            }$content['plans'] = Settings::plans();
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
            if ($path === 'referral-rewards/send') {
                ReferralRewards::send($r);
            } elseif ($path === 'referral-rewards/check') {
                ReferralRewards::check($r);
            } elseif ($path === 'referral-rewards/claim') {
                $r->validate(['milestone' => 'required|integer|in:5,10']);
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
        if ($path === 'ai/suggest' && $r->isMethod('POST')) {
            $v = $r->validate(['task' => 'required|in:rewrite,translate,review_requirements', 'language' => 'required|in:English', 'text' => 'required|string|min:30|max:12000', 'context' => 'nullable|string|max:8000', 'consent' => 'accepted']);

            return $ok(['suggestion' => WritingAssistant::suggest($u, $v)]);
        }
        if ($path === 'allowance') {
            $a = Settings::access($id);
            $l = $a['limits'];
            $pid = $a['purchase']?->id;
            $exports = $pid ? DB::table('product_usage')->where('owner_id', $id)->where('action', 'export')->where('reference', 'like', $pid.':%')->count() : 0;
            $scans = $pid ? DB::table('product_usage')->where('owner_id', $id)->where('action', 'match')->where('reference', $pid)->count() : 0;
            $plans = Settings::plans();
            unset($a['purchase']);

            return $ok([...$a, 'planName' => $plans[$a['plan']]['name'] ?? 'Free', 'daysLeft' => $a['accessUntil'] ? max(0, (int) ceil((strtotime($a['accessUntil']) - time()) / 86400)) : 0, 'caps' => ['resumes' => (int) $l['savedResumes'] + 1, 'letters' => (int) $l['savedLetters'], 'atsScans' => $l['atsScans'] ?? null, 'exports' => (int) ($l['exports'] ?? 0)], 'used' => ['exports' => $exports, 'savedResumes' => DB::table('resume_drafts')->where('owner_id', $id)->count(), 'savedLetters' => DB::table('cover_letter_drafts')->where('owner_id', $id)->count(), 'atsScans' => $scans, 'dailyMatches' => DB::table('product_usage')->where('owner_id', $id)->where('action', 'match')->whereDate('created_at', today())->count()]]);
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
        if ($path === 'features/use') {
            $r->validate(['action' => 'required|in:match']);
            DB::transaction(function () use ($id) {
                User::whereKey($id)->lockForUpdate()->first();
                $f = Settings::get('features')['match'];
                abort_unless($f['enabled'], 403);
                $a = Settings::access($id);
                $l = $a['limits'];
                $count = DB::table('product_usage')->where('owner_id', $id)->where('action', 'match')->whereDate('created_at', today())->count();
                $cap = $a['paid'] ? (int) ($l['dailyMatches'] ?? 100) : min((int) $f['limit'], (int) $l['dailyMatches']);
                if ($count >= $cap) {
                    throw ValidationException::withMessages(['limit' => 'Daily review allowance reached. Try again tomorrow.']);
                }
                if ($a['paid'] && ($l['atsScans'] ?? null) !== null && DB::table('product_usage')->where('owner_id', $id)->where('action', 'match')->where('reference', $a['purchase']->id)->count() >= (int) $l['atsScans']) {
                    throw ValidationException::withMessages(['limit' => 'Your ATS scan allowance is used. Upgrade your plan for more scans.']);
                }DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $id, 'action' => 'match', 'reference' => $a['purchase']->id ?? '', 'created_at' => now(), 'updated_at' => now()]);
                Settings::audit($id, 'resume_compared');
            });

            return $ok();
        }
        if ($path === 'applications' && $get) {
            return $ok(['applications' => DB::table('applications')->where('owner_id', $id)->orderByDesc('updated_at')->get()]);
        }
        if ($path === 'applications' && $r->isMethod('POST')) {
            $v = $r->validate(['company' => 'required|string|max:180', 'role' => 'required|string|max:180', 'stage' => 'required|in:saved,applied,interview,offer,closed', 'jobUrl' => 'nullable|url:http,https|max:1000', 'appliedAt' => 'nullable|date_format:Y-m-d', 'followUp' => 'nullable|date_format:Y-m-d', 'resumeId' => 'nullable|uuid', 'notes' => 'nullable|string|max:8000', 'id' => 'nullable|uuid', 'revision' => 'nullable|integer']);
            $result = DB::transaction(function () use ($v, $id) {
                User::whereKey($id)->lockForUpdate()->first();
                $feature = Settings::get('features')['tracker'];
                abort_unless($feature['enabled'], 403);
                if (! empty($v['resumeId'])) {
                    abort_unless(DB::table('resume_drafts')->where('owner_id', $id)->where('id', $v['resumeId'])->exists(), 422);
                }$record = ['owner_id' => $id, 'company' => $v['company'], 'role' => $v['role'], 'stage' => $v['stage'], 'job_url' => $v['jobUrl'] ?? '', 'applied_at' => $v['appliedAt'] ?? null, 'follow_up' => $v['followUp'] ?? null, 'resume_id' => $v['resumeId'] ?? null, 'notes' => $v['notes'] ?? '', 'updated_at' => now()];
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
            return $ok(['purchases' => DB::table('purchases')->where('owner_id', $id)->orderByDesc('created_at')->limit(200)->get()]);
        }
        if ($path === 'checkout/quote') {
            abort_unless($r->isMethod('POST'), 405);
            $v = $r->validate(['plan' => 'required|in:'.implode(',', Settings::paidPlanKeys()), 'coupon' => 'nullable|string|max:30']);
            Payments::guardAddon($u, $v['plan']);

            return $ok(BusinessControls::quote($v['plan'], $v['coupon'] ?? '', $u->id));
        }
        if ($path === 'checkout/start') {
            $r->validate(['plan' => 'required|in:'.implode(',', Settings::paidPlanKeys())]);
            Payments::guardAddon($u, $r->plan);

            return $ok(Payments::start($u, $r->plan, (string) $r->input('phone', ''), (string) $r->input('coupon', '')));
        }
        if ($path === 'checkout/verify' && config('app.preview') && $r->input('provider') === 'demo') {
            $r->validate(['orderId' => 'required|string|max:80']);
            Payments::demoVerify($u, $r->orderId);

            return $ok();
        }
        if ($path === 'checkout/verify') {
            if ($r->input('provider') === 'cashfree') {
                $r->validate(['orderId' => 'required|string|max:45']);
                CashfreeCheckout::verify($u, $r->orderId);

                return $ok();
            }
            $v = $r->validate(['razorpay_order_id' => 'required|string|max:100', 'razorpay_payment_id' => 'required|string|max:100', 'razorpay_signature' => 'required|string|size:64']);
            Payments::verify($u, $v);

            return $ok();
        }
        if ($path === 'referrals' && $get) {
            if (! $u->referral_code) {
                $u->forceFill(['referral_code' => Str::random(16)])->save();
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

            return $ok(['connected' => true, 'commissionEligible' => ! Referrals::config()['proOnly'] || (bool) (Settings::access($id)['limits']['referral'] ?? false), 'referredUsers' => $referredUsers, 'rules' => Referrals::config(), 'effectiveRateBps' => Referrals::rate($id), 'successfulBuyers' => Referrals::successfulBuyers($id), 'creator' => DB::table('creator_applications')->where('owner_id', $id)->first(), 'payoutProfile' => AffiliateProfiles::publicProfile($id), 'emailVerified' => (bool) $u->email_verified_at, 'code' => $u->referral_code, 'url' => url('/r/'.$u->referral_code), 'balance' => Referrals::balance($id), 'clicks' => DB::table('referral_clicks')->where('referrer_id', $id)->count(), 'signups' => User::where('referred_by', $id)->count(), 'commissions' => DB::table('referral_commissions')->where('referrer_id', $id)->orderByDesc('created_at')->limit(200)->get(['id', 'purchase_id', 'amount_paise', 'reversed_paise', 'rate_bps', 'available_at', 'created_at']), 'withdrawals' => DB::table('withdrawals')->where('owner_id', $id)->orderByDesc('created_at')->limit(100)->get(['id', 'amount_paise', 'method', 'destination_masked', 'status', 'reference', 'note', 'due_at', 'paid_at', 'created_at'])]);
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
        $v = $r->validate(['company' => 'required|string|max:160', 'role' => 'required|string|max:160', 'jobDescription' => 'nullable|string|max:40000']);
        $new = DB::transaction(function () use ($id, $row, $v) {
            User::whereKey($id)->lockForUpdate()->first();
            abort_unless(Settings::get('features')['builder']['enabled'], 403);
            $limit = min(Settings::access($id)['limits']['savedResumes'] + 1, Settings::get('features')['builder']['limit']);
            if (DB::table('resume_drafts')->where('owner_id', $id)->count() >= $limit) {
                throw ValidationException::withMessages(['limit' => 'Your resume allowance is full. Delete a draft or add an add-on pack.']);
            }
            $data = json_decode($row->content_json, true);
            unset($data['_profileId'], $data['_profileRevision']);
            $data['title'] = $v['role'].' · '.$v['company'];
            $data['company'] = $v['company'];
            $data['role'] = $v['role'];
            $nid = (string) Str::uuid();
            DB::table('resume_drafts')->insert(['id' => $nid, 'owner_id' => $id, 'title' => substr($data['title'], 0, 180), 'template' => $row->template, 'company' => $v['company'], 'role' => $v['role'], 'content_json' => json_encode($data), 'revision' => 1, 'is_primary' => false, 'parent_id' => $row->id, 'job_description' => $v['jobDescription'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            Settings::audit($id, 'resume_variant_created', $nid);

            return $nid;
        });

        return response()->json(['ok' => true, 'id' => $new, 'revision' => 1]);
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

        return ['generatedAt' => now()->toIso8601String(), 'account' => ['name' => $u->name, 'email' => $u->email, 'createdAt' => $u->created_at, 'referralCode' => $u->referral_code], 'profile' => (array) DB::table('profiles')->where('owner_id', $id)->first(), 'resumes' => $decode($rows('resume_drafts')), 'coverLetters' => $decode($rows('cover_letter_drafts')), 'applications' => $rows('applications'), 'purchases' => $rows('purchases', ['id', 'plan', 'amount_paise', 'refunded_paise', 'currency', 'status', 'coupon_code', 'purchased_at', 'access_until']), 'reviews' => $rows('feedback', ['display_name', 'rating', 'content', 'status', 'created_at']), 'referralCommissions' => DB::table('referral_commissions')->where('referrer_id', $id)->get(['amount_paise', 'rate_bps', 'created_at'])->map(fn ($x) => (array) $x)->all(), 'withdrawals' => $rows('withdrawals', ['amount_paise', 'destination_masked', 'status', 'reference', 'created_at']), 'payoutProfile' => (array) DB::table('payout_profiles')->where('owner_id', $id)->first(['status', 'destination_masked']), 'creatorApplication' => (array) DB::table('creator_applications')->where('owner_id', $id)->first(['status', 'audience', 'rate_bps', 'created_at']), 'activity' => DB::table('activity_events')->where('owner_id', $id)->orderByDesc('created_at')->limit(500)->get(['event', 'reference', 'created_at'])->map(fn ($x) => (array) $x)->all()];
    }

    // Personal data is erased. Purchase and payout records stay (legal/financial) but are detached from the person.
    public function deleteAccount(User $u): void
    {
        abort_if($u->is_admin, 422, 'The owner account cannot be deleted here.');
        abort_if(DB::table('withdrawals')->where('owner_id', $u->id)->whereIn('status', ['requested', 'processing'])->exists(), 422, 'Finish or cancel your open withdrawal first.');
        DB::transaction(function () use ($u) {
            $id = $u->id;
            foreach (['resume_drafts', 'cover_letter_drafts', 'applications', 'feedback', 'profiles', 'payout_profiles', 'creator_applications', 'referral_identities', 'referral_rewards', 'product_usage', 'mail_outbox'] as $t) {
                DB::table($t)->where($t === 'profiles' || $t === 'payout_profiles' || $t === 'referral_identities' ? 'owner_id' : 'owner_id', $id)->delete();
            }
            // Payout records stay for finance, but the bank/UPI/PAN details inside them are erased; only the masked destination remains.
            DB::table('withdrawals')->where('owner_id', $id)->update(['destination_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('{}'), 'updated_at' => now()]);
            DB::table('error_logs')->where('user_id', $id)->update(['user_id' => null]);
            DB::table('referral_clicks')->where('referrer_id', $id)->delete();
            DB::table('activity_events')->where('owner_id', $id)->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table('password_reset_tokens')->where('email', $u->email)->delete();
            DB::table('users')->where('id', $id)->update(['name' => 'Deleted user', 'email' => 'deleted-'.$id.'@deleted.invalid', 'password' => \Illuminate\Support\Facades\Hash::make(Str::random(40)), 'remember_token' => null, 'referral_code' => null, 'blocked' => true, 'email_verified_at' => null, 'updated_at' => now()]);
            Settings::audit(null, 'account_deleted', (string) $id);
        });
    }

    private function documents(Request $r, string $type, ?string $doc)
    {
        $u = $r->user();
        $id = $u->id;
        $table = $type === 'resumes' ? 'resume_drafts' : 'cover_letter_drafts';
        $one = $type === 'resumes' ? 'resume' : 'letter';
        $acc = Settings::access($id)['limits'];
        $limit = $type === 'resumes' ? min($acc['savedResumes'] + 1, Settings::get('features')['builder']['limit']) : min($acc['savedLetters'], Settings::get('features')['letter']['draftLimit'] ?? 500);
        $query = DB::table($table)->where('owner_id', $id);
        if ($r->isMethod('GET')) {
            if ($doc) {
                $row = (clone $query)->where('id', $doc)->first();
                abort_unless($row, 404);

                return response()->json(['ok' => true, $one => ['id' => $row->id, 'revision' => $row->revision, 'isPrimary' => (bool) ($row->is_primary ?? false), 'parentId' => $row->parent_id ?? null, 'jobDescription' => $row->job_description ?? '', 'data' => json_decode($row->content_json, true)]]);
            }

            return response()->json(['ok' => true, $type => $query->orderByDesc('updated_at')->get($type === 'resumes' ? ['id', 'title', 'template', 'company', 'role', 'revision', 'is_primary', 'parent_id', 'created_at', 'updated_at'] : ['id', 'title', 'template', 'company', 'role', 'revision', 'created_at', 'updated_at']), 'limit' => $limit]);
        }
        if ($r->isMethod('DELETE')) {
            abort_unless($doc && (clone $query)->where('id', $doc)->delete(), 404);
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
            if ($r->input('id')) {
                $did = $r->input('id');
                $rev = (int) $r->input('revision');
                abort_unless(DB::table($table)->where('id', $did)->where('owner_id', $id)->where('revision', $rev)->update([...$record, 'revision' => $rev + 1]), 409);
                $rev++;
            } else {
                if (DB::table($table)->where('owner_id', $id)->count() >= $limit) {
                    throw ValidationException::withMessages(['limit' => 'Your saved draft allowance is full. Edit or delete an existing draft.']);
                }$did = (string) Str::uuid();
                $rev = 1;
                DB::table($table)->insert([...$record, 'id' => $did, 'owner_id' => $id, 'revision' => 1, 'created_at' => now(), ...($type === 'resumes' ? ['is_primary' => ! DB::table('resume_drafts')->where('owner_id', $id)->exists()] : [])]);
            }Settings::audit($id, $one.'_saved', $did);

            return ['id' => $did, 'revision' => $rev];
        });
        if ($type === 'resumes') {
            MailAutomation::queue($u, 'resume_saved', $result['id'].':'.$result['revision']);
        }

        return response()->json(['ok' => true, ...$result]);
    }
}
