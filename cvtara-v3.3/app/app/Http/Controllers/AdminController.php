<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\AdminTable;
use App\Services\BusinessControls;
use App\Services\FastIndex;
use App\Services\MailAutomation;
use App\Services\SearchConsole;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        $get = $r->isMethod('GET');
        abort_unless($get || $r->isMethod('POST'), 405);
        if ($get && in_array($path, ['feedback/add', 'email/process', 'media', 'search/import', 'search/sync', 'indexing/submit', 'indexing/sitemap', 'indexing/settings', 'referrals/settings'])) {
            abort(405);
        }
        if (preg_match(SpeedAdminController::ROUTES, $path)) {
            return app(SpeedAdminController::class)->run($r, $path);
        }
        if (preg_match(CampaignAdminController::ROUTES, $path)) {
            return app(CampaignAdminController::class)->run($r, $path);
        }
        if (preg_match(PromoAdminController::ROUTES, $path)) {
            return app(PromoAdminController::class)->run($r, $path);
        }
        if (preg_match(OutreachAdminController::ROUTES, $path)) {
            return app(OutreachAdminController::class)->run($r, $path);
        }
        if (preg_match(SupportAdminController::ROUTES, $path)) {
            return app(SupportAdminController::class)->run($r, $path);
        }
        if (preg_match('#^report(/meta|/kpis)?$#', $path)) {
            return app(ReportController::class)->run($r, $path);
        }
        if (preg_match(AdminOpsController::ROUTES, $path)) {
            return app(AdminOpsController::class)->run($r, $path);
        }
        if (str_starts_with($path, 'setup/')) {
            return app(SetupController::class)->run($r, substr($path, 6));
        }
        if (str_starts_with($path, 'referrals')) {
            return app(AffiliateAdminController::class)->run($r, $path);
        }
        if (preg_match('#^content/(legal|features|library|blog|integrations|emails|seo|popup|site)$#', $path, $m)) {
            $key = $m[1];
            if ($get) {
                return $ok(['data' => $key === 'emails' ? \App\Services\MailAutomation::upgradeTemplates((array) Settings::get($key)) : Settings::get($key), 'revision' => Settings::revision($key), 'connections' => ['payments' => ! empty(Settings::providers()['payments']), 'email' => ! empty(Settings::providers()['email']), 'ai' => ! empty(Settings::providers()['ai']), 'grammar' => ! empty(Settings::providers()['grammar'])]]);
            }if (in_array($key, ['integrations', 'emails', 'legal', 'site'], true)) {
                AccountSecurity::requireRecent($r);
            }$r->validate(['data' => 'present|array', 'revision' => 'required|integer']);
            abort_if(strlen(json_encode($r->data)) > 4000000, 413);
            $data = $r->data;
            if ($key === 'legal') {
                $data = array_map(fn ($v) => $v ?? '', $data);
            }$this->validateSetting($key, $data);
            DB::transaction(function () use ($key, $data, $r, $id) {
                User::whereKey($id)->lockForUpdate()->first();
                abort_unless(Settings::revision($key) === $r->integer('revision'), 409);
                $old = Settings::get($key);
                Settings::put($key, $data);
                if ($key === 'blog') {
                    foreach ($data as $slug => $post) {
                        if ($post['published'] && ! ($old[$slug]['published'] ?? false)) {
                            User::whereIn('id', DB::table('profiles')->where('marketing_consent', true)->select('owner_id'))->chunkById(100, function ($users) use ($slug, $post) {
                                foreach ($users as $u) {
                                    MailAutomation::queue($u, 'blog_published', $slug, ['blog_title' => $post['title'], 'blog_url' => rtrim((string) config('app.url'), '/').('/article?slug='.$slug)]);
                                }
                            });
                        }
                    }
                }Settings::audit($id, 'owner_content_saved', $key);
            });

            return $ok(['data' => $data, 'revision' => Settings::revision($key)]);
        }
        if ($path === 'settings') {
            if ($get) {
                return $ok(['settings' => Settings::get('public', [])]);
            }$v = $r->validate(['heroTitle' => 'sometimes|string|max:200', 'heroDescription' => 'sometimes|string|max:1000', 'primaryCTA' => 'sometimes|string|max:80', 'secondaryCTA' => 'sometimes|string|max:80', 'accent' => 'sometimes|regex:/^#[a-fA-F0-9]{6}$/', 'pushEnabled' => 'sometimes|boolean', 'oneSignalAppId' => 'nullable|uuid', 'pushDelay' => 'sometimes|integer|min:0|max:300', 'pushMessage' => 'sometimes|string|max:500']);
            Settings::put('public', array_merge(Settings::get('public', []), $v));

            return $ok(['settings' => Settings::get('public')]);
        }
        if ($path === 'providers') {
            $p = Settings::providers();
            $fields = ['jobreader' => ['enabled', 'provider', 'scrapingbeeApiKey', 'zenrowsApiKey', 'customUrlTemplate', 'customApiKey', 'dailyLimit'], 'payments' => ['provider', 'mode', 'razorpayKeyId', 'razorpayKeySecret', 'razorpayWebhookSecret', 'phonepeMode', 'phonepeClientId', 'phonepeClientVersion', 'phonepeClientSecret', 'phonepeWebhookUsername', 'phonepeWebhookPassword'], 'email' => ['provider', 'resendApiKey', 'smtpHost', 'smtpPort', 'smtpUser', 'smtpPassword', 'fromEmail', 'fromName', 'replyTo', 'postalAddress'], 'ai' => ['provider', 'claudeApiKey', 'model', 'dailyLimit', 'writerApiKey'], 'grammar' => ['url', 'username', 'premiumApiKey'], 'analytics' => ['measurementId', 'searchConsoleProperty', 'searchConsoleServiceAccount'], 'push' => ['oneSignalAppId', 'oneSignalRestApiKey']];
            $secret = fn ($k) => preg_match('/Secret|ApiKey|Password|ServiceAccount|AuthToken/', $k);
            if ($get) {
                foreach ($p as &$service) {
                    foreach ($service as $key => &$v) {
                        if ($secret($key)) {
                            $v = ['configured' => (bool) $v];
                        }
                    }
                }

                return $ok(['providers' => $p, 'secureStorage' => true]);
            }AccountSecurity::requireRecent($r);
            $r->validate(['service' => 'required|in:'.implode(',', array_keys($fields)), 'values' => 'required|array']);
            if ($r->service === 'ai') {
                validator($r->values, ['provider' => 'sometimes|in:claude,standard', 'model' => 'nullable|string|max:80|regex:/^[a-z0-9.\-]*$/', 'dailyLimit' => 'nullable|integer|min:0|max:100000'])->validate();
            }
            if ($r->service === 'jobreader') {
                validator($r->values, ['enabled' => 'required|in:true,false', 'provider' => 'required|in:'.implode(',', \App\Services\JobReader::PROVIDERS), 'dailyLimit' => 'nullable|integer|min:1|max:100000', 'customUrlTemplate' => 'nullable|string|max:600'])->validate();
                if (($r->values['customUrlTemplate'] ?? '') !== '' && ! str_contains($r->values['customUrlTemplate'], '{url}')) {
                    abort(422, 'The custom address must contain {url} where the job link goes.');
                }
            }
            if ($r->service === 'payments') {
                validator($r->values, ['provider' => 'sometimes|in:razorpay,phonepe,both', 'mode' => 'sometimes|in:test,live', 'phonepeMode' => 'sometimes|in:test,live', 'phonepeClientVersion' => 'nullable|regex:/^[0-9]{1,4}$/'])->validate();
            }
            if ($r->service === 'grammar') {
                validator($r->values, ['url' => 'nullable|url:http,https|max:300', 'username' => 'nullable|string|max:190'])->validate();
            }
            foreach ($r->values as $k => $v) {
                abort_unless(in_array($k, $fields[$r->service]) && ($v === null || (is_string($v) && strlen($v) <= 12000)), 422);
                if (trim((string) $v) !== '') {
                    $p[$r->service][$k] = trim($v);
                }
            }if ($r->service === 'jobreader' && ($p['jobreader']['enabled'] ?? 'false') === 'true') {
                $j = $p['jobreader'];
                $ready = match ($j['provider'] ?? '') {
                    'scrapingbee' => ! empty($j['scrapingbeeApiKey']),
                    'zenrows' => ! empty($j['zenrowsApiKey']),
                    'custom' => ! empty($j['customUrlTemplate']),
                    default => false,
                };
                abort_unless($ready, 422, 'Enter the key (or address) for the selected reading service first.');
            }if ($r->service === 'payments') {
                $pay = $p['payments'];
                $choice = $pay['provider'] ?? 'razorpay';
                if (in_array($choice, ['razorpay', 'both'], true)) {
                    abort_unless(! empty($pay['razorpayKeyId']) && ! empty($pay['razorpayKeySecret']), 422, 'Enter the Razorpay Key ID and Key secret.');
                }
                if (in_array($choice, ['phonepe', 'both'], true)) {
                    abort_unless(\App\Services\PhonePe::configured($pay), 422, 'Enter the PhonePe Client ID and Client secret.');
                    abort_unless(! empty($pay['phonepeWebhookUsername']) && ! empty($pay['phonepeWebhookPassword']), 422, 'Enter the PhonePe webhook username and password you set in the PhonePe dashboard.');
                    try {
                        \App\Services\PhonePe::token($pay, true);
                    } catch (\Throwable $e) {
                        abort(422, 'PhonePe did not accept these credentials. Check the Client ID, Client version, Client secret and the Test/Live mode.');
                    }
                }
            }if (! empty($p['email']['fromEmail'])) {
                abort_unless(filter_var($p['email']['fromEmail'], FILTER_VALIDATE_EMAIL), 422);
            }if (! empty($p['email']['replyTo'])) {
                abort_unless(filter_var($p['email']['replyTo'], FILTER_VALIDATE_EMAIL), 422, 'Enter a valid reply-to address.');
            }if (isset($p['email']['postalAddress'])) {
                abort_if(mb_strlen($p['email']['postalAddress']) > 200, 422, 'Keep the postal address under 200 characters.');
            }Settings::put('providers', Crypt::encryptString(json_encode($p)));
            Settings::audit($id, 'provider_settings_saved', $r->service);

            return $ok();
        }
        if ($path === 'business-controls') {
            if ($get) {
                return $ok(['data' => BusinessControls::get()]);
            }abort_unless($r->isMethod('POST'), 405);
            AccountSecurity::requireRecent($r);

            return $ok(['data' => BusinessControls::save($r)]);
        }
        if ($path === 'plans') {
            if ($get) {
                return $ok(['plans' => Settings::plans()]);
            }AccountSecurity::requireRecent($r);
            $v = $r->validate(['plans' => 'required|array', 'plans.*.name' => 'required|string|max:120', 'plans.*.tagline' => 'nullable|string|max:200', 'plans.*.price' => 'required|integer|min:0|max:99999', 'plans.*.mrp' => 'nullable|integer|min:0|max:99999', 'plans.*.accessDays' => 'required|integer|min:0|max:3660', 'plans.*.savedResumes' => 'required|integer|min:0|max:500', 'plans.*.savedLetters' => 'required|integer|min:0|max:500', 'plans.*.atsScans' => 'nullable|integer|min:0|max:100000', 'plans.*.dailyMatches' => 'nullable|integer|min:0|max:1000', 'plans.*.exports' => 'nullable|integer|min:0|max:100000', 'plans.*.popular' => 'sometimes|boolean', 'plans.*.referral' => 'sometimes|boolean', 'plans.*.bestValue' => 'sometimes|boolean', 'plans.*.sale' => 'sometimes|boolean', 'plans.*.planPrice' => 'nullable|integer|min:1|max:99999', 'plans.*.perks' => 'sometimes|array|max:12', 'plans.*.perks.*' => 'string|max:120', 'plans.*.audience' => 'sometimes|in:fresher,experienced,all']);
            $out = Settings::plans();
            foreach ($v['plans'] as $k => $in) {
                abort_unless(isset($out[$k]), 422, 'Unknown plan.');
                abort_if($out[$k]['type'] === 'plan' && $k !== 'free' && ($in['accessDays'] ?? 0) < 1, 422, 'Plans need at least 1 day of access.');
                $in['atsScans'] = empty($in['atsScans']) ? null : (int) $in['atsScans'];
                $in['mrp'] = (int) ($in['mrp'] ?? 0);
                abort_if($k === 'free' && ! empty($in['sale']), 422, 'The free plan is not sold.');
                $in['perks'] = array_values(array_filter(array_map('trim', $in['perks'] ?? $out[$k]['perks'])));
                $out[$k] = array_merge($out[$k], array_intersect_key($in, $out[$k]));
            }Settings::put('plans', $out);
            Settings::audit($id, 'plans_saved');

            return $ok(['plans' => Settings::plans()]);
        }
        if ($path === 'purchases') {
            $d = AdminTable::query($r, DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->select(['p.*', 'u.email', 'u.name']), [
                'search' => ['u.email', 'u.name', 'p.payment_reference', 'p.provider_order_id', 'p.plan'],
                'filters' => ['status' => 'p.status', 'provider' => 'p.provider', 'plan' => 'p.plan'],
                'date' => 'p.created_at',
                'sorts' => ['date' => 'p.created_at', 'amount' => 'p.amount_paise', 'status' => 'p.status', 'plan' => 'p.plan'],
                'sort' => 'date',
                'key' => 'p.id',
            ]);

            return $ok(['purchases' => $d['rows']] + $d);
        }
        if ($path === 'users') {
            // Only the requested page of accounts and four cheap counts: the heavy activity statistics live in 'dashboard'.
            $uq = DB::table('users')->where('email', '!=', \App\Services\AccountEraser::PLACEHOLDER);
            $live = fn () => DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->where('provider', '!=', 'referral_reward')->select('owner_id');
            $ever = fn () => DB::table('purchases')->whereNotNull('purchased_at')->select('owner_id');
            $d = AdminTable::query($r, $uq->select(['id', 'name', 'email', 'created_at', 'blocked', 'is_admin']), [
                'search' => ['email', 'name'],
                'filters' => ['f' => function ($q, $f) use ($live, $ever) {
                    if ($f === 'owner') {
                        $q->where('is_admin', true);
                    } elseif (in_array($f, ['blocked', 'paid', 'expired', 'normal'], true)) {
                        $q->where('is_admin', false)->where('blocked', $f === 'blocked');
                        match ($f) {
                            'paid' => $q->whereIn('id', $live()),
                            'expired' => $q->whereIn('id', $ever())->whereNotIn('id', $live()),
                            'normal' => $q->whereNotIn('id', $ever()),
                            default => null,
                        };
                    }
                }],
                'date' => 'created_at',
                'sorts' => ['joined' => 'created_at', 'name' => 'name', 'email' => 'email'],
                'sort' => 'joined',
                'key' => 'id',
            ]);
            $ids = array_map(fn ($u) => $u->id, $d['rows']);
            $profiles = DB::table('profiles')->whereIn('owner_id', $ids)->get()->keyBy('owner_id');
            $counts = fn ($t) => DB::table($t)->whereIn('owner_id', $ids)->groupBy('owner_id')->selectRaw('owner_id, count(*) c')->pluck('c', 'owner_id');
            $resumeCounts = $counts('resume_drafts');
            $appCounts = $counts('applications');
            $everPaid = DB::table('purchases')->whereIn('owner_id', $ids)->whereNotNull('purchased_at')->distinct()->pluck('owner_id')->flip();
            $acc = Settings::accessMany($ids);
            $users = array_map(fn ($u) => [...(array) ($profiles[$u->id] ?? []), 'owner_id' => (string) $u->id, 'email' => $u->email, 'name' => $u->name, 'created_at' => $u->created_at, 'blocked' => (bool) $u->blocked, 'isAdmin' => (bool) $u->is_admin, 'paid' => $acc[$u->id]['paid'], 'ever_paid' => isset($everPaid[$u->id]), 'last_plan' => $acc[$u->id]['plan'], 'accessUntil' => $acc[$u->id]['accessUntil'], 'resumes' => $resumeCounts[$u->id] ?? 0, 'applications' => $appCounts[$u->id] ?? 0], $d['rows']);
            $kpis = \Illuminate\Support\Facades\Cache::remember('admin-user-kpis', 20, function () {
                $all = User::count();
                $paid = DB::table('purchases')->where('provider', '!=', 'referral_reward')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->distinct('owner_id')->count('owner_id');

                return ['totalUsers' => $all, 'paidUsers' => $paid, 'freeUsers' => $all - $paid, 'blockedUsers' => User::where('blocked', true)->count()];
            });

            return $ok(['users' => $users, 'usersTotal' => $d['total'], 'perPage' => $d['per']] + $d + $kpis);
        }
        if (in_array($path, ['overview', 'dashboard'])) {
            $days = in_array((int) $r->query('days', 30), [1, 7, 30, 90]) ? (int) $r->query('days', 30) : 30;
            // Several console panels ask for these numbers when the console opens; one computation serves them all.
            $data = \Illuminate\Support\Facades\Cache::remember('admin-dashboard-'.$days, 30, function () use ($days) {
                $activity = DB::table('activity_events as a')->leftJoin('users as u', 'a.owner_id', '=', 'u.id')->where('a.created_at', '>=', now()->subDays($days))->orderByDesc('a.created_at')->limit(20)->get(['a.*', 'u.name', 'u.email']);
                $events = DB::table('activity_events')->where('created_at', '>=', now()->subDays($days))->selectRaw('event,count(*) as count')->groupBy('event')->get();
                $paid = DB::table('purchases')->where('provider', '!=', 'referral_reward')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->distinct('owner_id')->count('owner_id');
                $all = User::count();
                $data = ['users' => [], 'usersTotal' => $all, 'blockedUsers' => User::where('blocked', true)->count(), 'totalUsers' => $all, 'paidUsers' => $paid, 'freeUsers' => $all - $paid, 'resumes' => DB::table('resume_drafts')->count(), 'applications' => DB::table('applications')->count(), 'activityCount' => $events->sum('count'), 'activity' => $activity, 'eventCounts' => $events, 'reviews' => DB::table('feedback')->selectRaw('status,count(*) as count')->groupBy('status')->get(), 'purchases' => DB::table('purchases')->where('created_at', '>=', now()->subDays($days))->selectRaw('status,count(*) as count,SUM(amount_paise-refunded_paise) as amount')->groupBy('status')->get(), 'daily' => DB::table('activity_events')->where('created_at', '>=', now()->subDays($days))->selectRaw('DATE(created_at) as day,event,count(*) as count')->groupByRaw('DATE(created_at),event')->orderBy('day')->get()];
                $data['totalResumes'] = $data['resumes'];
                $data['totalApplications'] = $data['applications'];
                $data['stages'] = DB::table('applications')->selectRaw('stage,count(*) as count')->groupBy('stage')->get();
                $data['expiredUsers'] = User::whereIn('id', DB::table('purchases')->whereNotNull('purchased_at')->select('owner_id'))->whereNotIn('id', DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->select('owner_id'))->count();

                // The cache only keeps plain arrays (it refuses to rebuild PHP objects), so store the JSON shape.
                return json_decode(json_encode($data), true);
            });
            if ($path === 'overview') {
                $data['users'] = $data['totalUsers'];
            }

            return $ok($data);
        }
        if ($path === 'live') {
            // The "Live stats" card refreshes every 30 seconds: five indexed counts, shared by every open console tab.
            return $ok(\Illuminate\Support\Facades\Cache::remember('admin-live', 20, function () {
                $day = now()->startOfDay();
                $ev = fn ($like) => DB::table('activity_events')->where('created_at', '>=', $day)->where('event', 'like', $like)->count();

                return ['activeUsers' => DB::table('activity_events')->where('created_at', '>=', now()->subDay())->whereNotNull('owner_id')->distinct('owner_id')->count('owner_id'), 'resumesToday' => $ev('%resume%'), 'revenueTodayPaise' => (int) DB::table('purchases')->where('created_at', '>=', $day)->where('provider', '!=', 'referral_reward')->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->sum(DB::raw('amount_paise - refunded_paise')), 'scansToday' => DB::table('activity_events')->where('created_at', '>=', $day)->where(fn ($w) => $w->where('event', 'like', '%compared%')->orWhere('event', 'like', '%ats%'))->count(), 'signupsToday' => User::where('created_at', '>=', $day)->count(), 'totalUsers' => User::count()];
            }));
        }
        if ($path === 'users/block') {
            abort_unless($r->isMethod('POST'), 405);
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['userId' => 'required|integer', 'blocked' => 'required|boolean', 'note' => 'required|string|min:5|max:500']);
            $target = User::findOrFail($v['userId']);
            abort_if($target->is_admin || $target->id === $id, 422, 'Owner accounts cannot be blocked here.');
            DB::transaction(function () use ($target, $v, $id) {
                User::whereKey($target->id)->lockForUpdate()->first();
                DB::table('users')->where('id', $target->id)->update(['blocked' => (bool) $v['blocked'], 'updated_at' => now()]);
                if ($v['blocked']) {
                    DB::table('sessions')->where('user_id', $target->id)->delete();
                }
                Settings::audit($id, $v['blocked'] ? 'user_blocked' : 'user_unblocked', $target->id.': '.$v['note']);
            });

            return $ok(['blocked' => (bool) $v['blocked']]);
        }
        if ($path === 'letter-summary') {
            return $ok(['total' => DB::table('cover_letter_drafts')->count()]);
        }
        if ($path === 'reports') {
            $days = (int) $r->query('days', 30);
            abort_unless(in_array($days, [1, 7, 30, 90]), 422);
            $q = DB::table('page_views')->where('created_at', '>=', now()->subDays($days));

            return $ok(['pages' => (clone $q)->selectRaw('path,count(*) as views,count(DISTINCT visitor_id) as visitors')->groupBy('path')->orderByDesc('views')->limit(100)->get(), 'daily' => (clone $q)->selectRaw('DATE(created_at) as day,count(*) as views')->groupByRaw('DATE(created_at)')->orderBy('day')->get(), 'sources' => (clone $q)->selectRaw('source,count(*) as views')->groupBy('source')->get(), 'events' => DB::table('activity_events')->where('created_at', '>=', now()->subDays($days))->selectRaw('event,count(*) as count')->groupBy('event')->get(), 'checkouts' => DB::table('purchases')->selectRaw("CASE WHEN status='created' THEN 'started' ELSE status END as status,count(*) as count")->groupBy('status')->get(), 'email' => DB::table('mail_outbox')->selectRaw('status,count(*) as count')->groupBy('status')->get(), 'traffic' => (clone $q)->selectRaw('count(*) as views,count(DISTINCT visitor_id) as visitors')->first(), 'search' => Settings::get('searchData')]);
        }
        if ($path === 'reviews') {
            if ($get) {
                $d = AdminTable::query($r, DB::table('feedback as f')->join('users as u', 'u.id', '=', 'f.owner_id')->select(['f.*', 'u.email']), [
                    'search' => ['f.display_name', 'f.content', 'u.email'],
                    'filters' => ['status' => 'f.status', 'source' => 'f.source', 'rating' => 'f.rating'],
                    'date' => 'f.created_at',
                    'sorts' => ['date' => 'f.created_at', 'rating' => 'f.rating', 'name' => 'f.display_name'],
                    'sort' => 'date',
                    'key' => 'f.id',
                ]);

                return $ok(['reviews' => $d['rows'], 'counts' => DB::table('feedback')->selectRaw('status,count(*) as count')->groupBy('status')->pluck('count', 'status')] + $d);
            }$r->validate(['id' => 'required|uuid', 'status' => 'required|in:pending,published,hidden']);
            $row = DB::table('feedback')->where('id', $r->id)->first();
            abort_unless($row, 404);
            abort_if($r->status === 'published' && (! $row->consent || $row->source === 'dummy'), 422);
            DB::table('feedback')->where('id', $r->id)->update(['status' => $r->status, 'updated_at' => now()]);

            return $ok();
        }
        if ($path === 'feedback/add') {
            $v = $r->validate(['name' => 'required|string|max:100', 'content' => 'required|string|min:10|max:2000', 'rating' => 'required|integer|min:1|max:5', 'source' => 'required|in:dummy,verified', 'consentReference' => 'required_if:source,verified|nullable|string|min:10|max:1000', 'consent' => 'required_if:source,verified|boolean', 'customerEmail' => 'required_if:source,verified|nullable|email|max:190']);
            $real = $v['source'] === 'verified';
            abort_if($real && ! $v['consent'], 422);
            if ($real) {
                $cust = User::where('email', mb_strtolower($v['customerEmail']))->first();
                abort_unless($cust && ! $cust->is_admin && DB::table('purchases')->where('owner_id', $cust->id)->whereIn('status', ['paid', 'partially_refunded', 'refunded', 'disputed'])->whereNotNull('purchased_at')->where('provider', '!=', 'referral_reward')->exists(), 422, 'A verified review must belong to a real customer who has paid. Check the account email.');
                $v['consentReference'] = 'customer #'.$cust->id.' · '.($v['consentReference'] ?? '');
                Settings::audit($id, 'verified_review_added', (string) $cust->id);
            }
            DB::table('feedback')->insert(['id' => (string) Str::uuid(), 'owner_id' => $id, 'display_name' => $v['name'], 'rating' => $v['rating'], 'content' => $v['content'], 'source' => $real ? 'owner_verified' : 'dummy', 'consent' => $real, 'consented_at' => $real ? now() : null, 'consent_reference' => $v['consentReference'] ?? '', 'created_at' => now(), 'updated_at' => now()]);

            return $ok();
        }
        if ($path === 'email/jobs') {
            $d = AdminTable::query($r, DB::table('mail_outbox')->select(['id', 'recipient', 'trigger', 'subject', 'status', 'error', 'due_at', 'sent_at', 'attempts', 'created_at']), [
                'search' => ['recipient', 'subject'],
                'filters' => ['status' => 'status', 'trigger' => 'trigger'],
                'date' => 'created_at',
                'sorts' => ['date' => 'created_at', 'status' => 'status', 'to' => 'recipient'],
                'sort' => 'date',
                'key' => 'id',
            ]);

            return $ok(['jobs' => $d['rows'], 'counts' => DB::table('mail_outbox')->selectRaw('status,count(*) as count')->groupBy('status')->pluck('count', 'status'), 'delivery' => 'Configured SMTP / Resend', 'scheduler' => 'Hostinger cron processes queued jobs.', 'heartbeat' => Settings::get('cronHeartbeat')] + $d);
        }
        if ($path === 'email/preview') {
            abort_unless($r->isMethod('POST'), 405);
            $v = $r->validate(['subject' => 'required|string|max:180', 'body' => 'required|string|max:20000', 'trigger' => 'nullable|string|max:60']);

            return $ok(['html' => \App\Services\EmailRenderer::render($v['subject'], $v['body'], \App\Services\EmailRenderer::isMarketing((string) ($v['trigger'] ?? '')), $r->user()->id)['html']]);
        }
        if ($path === 'email/process') {
            MailAutomation::reminders();
            \App\Services\Campaigns::run();

            return $ok(MailAutomation::process());
        }
        if ($path === 'media') {
            $r->validate(['base64' => 'required|string|max:700000']);
            $bytes = base64_decode($r->base64, true);
            abort_unless($bytes && strlen($bytes) <= 500000, 422);
            $info = @getimagesizefromstring($bytes);
            abort_unless($info && $info['mime'] === 'image/webp' && $info[0] <= 4000 && $info[1] <= 4000, 422);
            $name = Str::uuid().'.webp';
            Storage::disk('local')->put('media/'.$name, $bytes);

            return $ok(['url' => '/media/'.$name]);
        }
        if ($path === 'seo') {
            return $ok(['origin' => config('app.url'), 'seo' => Settings::get('seo'), 'routes' => app(ContentController::class)->pages()]);
        }
        if ($path === 'page-audit') {
            // Hundreds of programmatic pages: the checklist is worked out once a minute and sent one page of rows at a time.
            $rows = \Illuminate\Support\Facades\Cache::remember('admin-page-audit', 60, fn () => array_map(fn ($p) => array_intersect_key($p, array_flip(['path', 'title', 'primary', 'titleHits', 'descriptionHits', 'headingHits', 'bodyHits', 'altHits', 'wordCount', 'score'])) + ['section' => preg_match('#^/([a-z0-9-]+)/#', $p['path'], $m) ? $m[1] : 'main'], app(ContentController::class)->audit()));
            $d = AdminTable::rows($r, $rows, [
                'search' => ['title', 'path', 'primary'],
                'filters' => ['section' => 'section', 'score' => fn ($p, $v) => match ($v) {
                    'low' => $p['score'] < 50,
                    'mid' => $p['score'] >= 50 && $p['score'] < 80,
                    'high' => $p['score'] >= 80,
                    default => true,
                }],
                'sorts' => ['page' => 'title', 'path' => 'path', 'words' => 'wordCount', 'score' => 'score', 'body' => 'bodyHits'],
                'sort' => 'path',
                'dir' => 'asc',
            ]);

            $sections = array_count_values(array_column($rows, 'section'));
            arsort($sections);

            return $ok(['pages' => $d['rows'], 'sections' => $sections] + $d);
        }
        if ($path === 'search/rows') {
            $dim = $r->query('dimension') === 'page' ? 'page' : 'query';
            $data = Settings::get('searchData', []);
            $d = AdminTable::rows($r, is_array($data[$dim] ?? null) ? $data[$dim] : [], [
                'search' => ['key'],
                'sorts' => ['key' => 'key', 'clicks' => 'clicks', 'impressions' => 'impressions', 'ctr' => 'ctr', 'position' => 'position'],
                'sort' => 'clicks',
            ]);

            return $ok($d);
        }
        if ($path === 'search/import') {
            $v = $r->validate(['dimension' => 'required|in:query,page', 'startDate' => 'required|date_format:Y-m-d', 'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate', 'rows' => 'required|array|max:5000', 'rows.*.key' => 'required|string|max:2000', 'rows.*.clicks' => 'required|numeric|min:0', 'rows.*.impressions' => 'required|numeric|min:0', 'rows.*.ctr' => 'required|numeric|min:0|max:1', 'rows.*.position' => 'required|numeric|min:0']);
            $old = Settings::get('searchData', []);
            if (($old['startDate'] ?? '') !== $v['startDate'] || ($old['endDate'] ?? '') !== $v['endDate']) {
                $old = [];
            }Settings::put('searchData', array_merge($old, [$v['dimension'] => $v['rows'], 'startDate' => $v['startDate'], 'endDate' => $v['endDate'], 'source' => 'Owner-imported Search Console CSV', 'updatedAt' => now()->toIso8601String()]));

            return $ok();
        }
        if ($path === 'search/sync') {
            return $ok(['data' => app(SearchConsole::class)->sync()]);
        }
        if ($path === 'indexing') {
            return $ok(FastIndex::status());
        }
        if ($path === 'indexing/settings') {
            $v = $r->validate(['auto' => 'required|boolean', 'google' => 'required|boolean']);
            FastIndex::settings($v['auto'], $v['google']);

            return $ok(FastIndex::status());
        }
        if ($path === 'indexing/submit') {
            $v = $r->validate(['mode' => 'required|in:changed,all,urls', 'urls' => 'required_if:mode,urls|nullable|string|max:20000', 'google' => 'required|boolean']);
            $paths = $v['mode'] === 'all' ? 'all' : null;
            if ($v['mode'] === 'urls') {
                $origin = FastIndex::origin();
                $paths = [];
                foreach (preg_split('/\s+/', trim($v['urls'])) as $u) {
                    abort_unless(str_starts_with($u, $origin.'/') && filter_var($u, FILTER_VALIDATE_URL), 422, 'Each address must start with '.$origin.'/ (one per line).');
                    $paths[] = substr($u, strlen($origin));
                }
                abort_unless(count($paths) <= 100, 422, 'Send at most 100 addresses at a time.');
            }

            return $ok(['entry' => FastIndex::submit($paths, $v['google'])] + FastIndex::status());
        }
        if ($path === 'indexing/sitemap') {
            FastIndex::sitemap();

            return $ok(FastIndex::status());
        }
        abort(404);
    }

    private function validateSetting(string $k, array $d): void
    {
        if ($k === 'blog') {
            foreach ($d as $slug => $p) {
                abort_unless(preg_match('#^[a-z0-9][a-z0-9/-]{0,160}$#', $slug) && ! str_contains($slug, '..'), 422);
                validator($p, ['title' => 'required|string|max:180', 'description' => 'present|nullable|string|max:500', 'body' => 'present|nullable|string|max:40000', 'category' => 'present|nullable|string|max:100', 'published' => 'required|boolean', 'imageUrl' => 'nullable|string|max:2000', 'imageAlt' => 'nullable|string|max:200', 'faq' => 'nullable|string|max:4000', 'keywords' => 'nullable|string|max:1000'])->validate();
                if (! empty($p['imageUrl'])) {
                    abort_unless(preg_match('#^(https://|/(?!/))#', $p['imageUrl']), 422);
                }
            }
        }
        if ($k === 'features') {
            foreach (['builder', 'letter', 'match', 'tracker', 'templates', 'blog'] as $key) {
                validator($d[$key] ?? [], ['enabled' => 'required|boolean', 'position' => 'required|integer|min:1|max:20', 'limit' => 'required|integer|min:1|max:500', 'draftLimit' => 'sometimes|integer|min:1|max:100'])->validate();
            }validator($d, ['watermarkText' => 'required|string|max:100'])->validate();
        }
        if ($k === 'integrations') {
            validator($d, array_fill_keys(['payments', 'email', 'ai', 'analytics', 'push'], 'required|boolean') + ['grammar' => 'sometimes|boolean'])->validate();
        }
        if ($k === 'popup') {
            validator($d, ['enabled' => 'required|boolean', 'delaySeconds' => 'required|integer|min:0|max:600', 'showSeconds' => 'required|integer|min:3|max:30', 'gapSeconds' => 'required|integer|min:3|max:120', 'useLiveActivity' => 'required|boolean', 'custom' => 'present|array|max:20', 'custom.*.title' => 'nullable|string|max:60', 'custom.*.text' => 'required|string|max:200', 'custom.*.icon' => 'nullable|string|max:8', 'custom.*.enabled' => 'required|boolean'])->validate();
            \Illuminate\Support\Facades\Cache::forget('public-activity');
        }
        if ($k === 'site') {
            validator($d, ['announce' => 'nullable|string|max:300', 'announceLink' => 'nullable|string|max:300', 'heroPill' => 'nullable|string|max:120', 'heroTitle' => 'required|string|max:200', 'heroRoles' => 'present|array|max:12', 'heroRoles.*' => 'string|max:60', 'heroLead' => 'required|string|max:600', 'ctaPrimary' => 'required|string|max:60', 'ctaSecondary' => 'required|string|max:60', 'ctaFinalTitle' => 'nullable|string|max:200', 'ctaFinalText' => 'nullable|string|max:400', 'pages' => 'present|array', 'pages.*.title' => 'nullable|string|max:180', 'pages.*.description' => 'nullable|string|max:400', 'pages.*.keywords' => 'nullable|string|max:400'])->validate();
        }
        if ($k === 'seo') {
            validator($d, ['sitemapEnabled' => 'required|boolean', 'searchIndexing' => 'required|boolean', 'siteUrl' => 'nullable|url:https|max:255', 'pages' => 'present|array'])->validate();
        }
        if ($k === 'emails') {
            foreach ($d as $t) {
                validator($t, ['id' => 'required|string|max:80', 'subject' => 'required|string|max:180', 'body' => 'required|string|max:20000', 'enabled' => 'required|boolean', 'trigger' => 'required|in:profile_created,password_reset,resume_saved,purchase_verified,abandoned_purchase,blog_published,access_expiring,access_expiring_7,access_expiring_4,access_expiring_1,followup_due,application_status,download_locked,email_verification,refund_requested,refund_processed,refund_rejected,expert_review_delivered,nurture,monthly_digest,manual', 'delayHours' => 'required|integer|min:0|max:720'])->validate();
            }
        }
        if ($k === 'legal') {
            foreach ($d as $field => $v) {
                abort_unless(array_key_exists($field, Settings::defaults()['legal']) && is_string($v) && strlen($v) <= 40000, 422);
            }
        }
        if ($k === 'library') {
            validator($d, ['templates' => 'present|array|max:150', 'letters' => 'present|array|max:150'])->validate();
        }
    }
}
