<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\BusinessControls;
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
        if ($get && in_array($path, ['feedback/add', 'email/process', 'media', 'search/import', 'search/sync', 'referrals/settings'])) {
            abort(405);
        }
        if (preg_match(SpeedAdminController::ROUTES, $path)) {
            return app(SpeedAdminController::class)->run($r, $path);
        }
        if (preg_match(PromoAdminController::ROUTES, $path)) {
            return app(PromoAdminController::class)->run($r, $path);
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
                return $ok(['data' => Settings::get($key), 'revision' => Settings::revision($key), 'connections' => ['payments' => ! empty(Settings::providers()['payments']), 'email' => ! empty(Settings::providers()['email']), 'ai' => ! empty(Settings::providers()['ai'])]]);
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
            $fields = ['verification' => ['enabled', 'twilioAccountSid', 'twilioAuthToken', 'twilioVerifyServiceSid'], 'payouts' => ['enabled', 'mode', 'cashfreeClientId', 'cashfreeClientSecret', 'cashfreePublicKey', 'maxTransferRupees', 'acceptanceConfirmed'], 'jobreader' => ['enabled', 'provider', 'scrapingbeeApiKey', 'zenrowsApiKey', 'customUrlTemplate', 'customApiKey', 'dailyLimit'], 'payments' => ['provider', 'mode', 'razorpayKeyId', 'razorpayKeySecret', 'razorpayWebhookSecret', 'cashfreeClientId', 'cashfreeClientSecret'], 'email' => ['provider', 'resendApiKey', 'smtpHost', 'smtpPort', 'smtpUser', 'smtpPassword', 'fromEmail', 'fromName', 'replyTo'], 'ai' => ['provider', 'writerApiKey', 'model'], 'analytics' => ['measurementId', 'searchConsoleProperty', 'searchConsoleServiceAccount'], 'push' => ['oneSignalAppId', 'oneSignalRestApiKey']];
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
            if ($r->service === 'verification') {
                AccountSecurity::requireRecent($r);
                validator($r->values, ['enabled' => 'required|in:true,false', 'twilioAccountSid' => 'sometimes|regex:/^AC[a-f0-9]{32}$/', 'twilioVerifyServiceSid' => 'sometimes|regex:/^VA[a-f0-9]{32}$/'])->validate();
            }
            if ($r->service === 'jobreader') {
                validator($r->values, ['enabled' => 'required|in:true,false', 'provider' => 'required|in:'.implode(',', \App\Services\JobReader::PROVIDERS), 'dailyLimit' => 'nullable|integer|min:1|max:100000', 'customUrlTemplate' => 'nullable|string|max:600'])->validate();
                if (($r->values['customUrlTemplate'] ?? '') !== '' && ! str_contains($r->values['customUrlTemplate'], '{url}')) {
                    abort(422, 'The custom address must contain {url} where the job link goes.');
                }
            }
            if ($r->service === 'payouts') {
                AccountSecurity::requireRecent($r);
                if (DB::table('withdrawals')->where('provider', 'cashfree')->where(function ($q) {
                    $q->where('status', 'processing')->orWhere('paid_at', '>', now()->subDays(30));
                })->exists()) {
                    foreach (['cashfreeClientId', 'cashfreeClientSecret', 'cashfreePublicKey', 'mode'] as $key) {
                        $incoming = $r->values[$key] ?? '';
                        abort_if($incoming !== '' && $incoming !== ($p['payouts'][$key] ?? ''), 422, 'Reconcile outstanding Cashfree transfers before changing credentials or mode.');
                    }
                }validator($r->values, ['enabled' => 'required|in:true,false', 'mode' => 'required|in:sandbox,production', 'maxTransferRupees' => 'required|integer|min:1000|max:100000', 'acceptanceConfirmed' => 'required|in:true,false'])->validate();
                abort_if(($r->values['enabled'] ?? 'false') === 'true' && ($r->values['acceptanceConfirmed'] ?? 'false') !== 'true', 422, 'Complete Cashfree activation and sandbox acceptance first.');
            }foreach ($r->values as $k => $v) {
                abort_unless(in_array($k, $fields[$r->service]) && is_string($v) && strlen($v) <= 12000, 422);
                if (trim($v) !== '') {
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
                abort_unless(in_array($p['payments']['provider'] ?? 'razorpay', ['razorpay', 'cashfree']), 422);
            }if ($r->service === 'payments' && ($p['payments']['provider'] ?? '') === 'cashfree') {
                abort_unless(! empty($p['payments']['cashfreeClientId']) && ! empty($p['payments']['cashfreeClientSecret']), 422, 'Enter the Cashfree App ID and Secret key.');
            }if ($r->service === 'payments' && ($p['payments']['provider'] ?? '') === 'razorpay') {
                abort_unless(! empty($p['payments']['razorpayKeyId']) && ! empty($p['payments']['razorpayKeySecret']), 422, 'Enter the Razorpay Key ID and Key secret.');
            }if (! empty($p['email']['fromEmail'])) {
                abort_unless(filter_var($p['email']['fromEmail'], FILTER_VALIDATE_EMAIL), 422);
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
            $v = $r->validate(['plans' => 'required|array', 'plans.*.name' => 'required|string|max:120', 'plans.*.tagline' => 'nullable|string|max:200', 'plans.*.price' => 'required|integer|min:0|max:99999', 'plans.*.mrp' => 'nullable|integer|min:0|max:99999', 'plans.*.accessDays' => 'required|integer|min:0|max:3660', 'plans.*.savedResumes' => 'required|integer|min:0|max:500', 'plans.*.savedLetters' => 'required|integer|min:0|max:500', 'plans.*.atsScans' => 'nullable|integer|min:0|max:100000', 'plans.*.dailyMatches' => 'nullable|integer|min:0|max:1000', 'plans.*.exports' => 'nullable|integer|min:0|max:100000', 'plans.*.popular' => 'sometimes|boolean', 'plans.*.referral' => 'sometimes|boolean', 'plans.*.perks' => 'sometimes|array|max:12', 'plans.*.perks.*' => 'string|max:120', 'plans.*.audience' => 'sometimes|in:fresher,experienced,all']);
            $out = Settings::plans();
            foreach ($v['plans'] as $k => $in) {
                abort_unless(isset($out[$k]), 422, 'Unknown plan.');
                abort_if($out[$k]['type'] === 'plan' && $k !== 'free' && ($in['accessDays'] ?? 0) < 1, 422, 'Plans need at least 1 day of access.');
                $in['atsScans'] = empty($in['atsScans']) ? null : (int) $in['atsScans'];
                $in['mrp'] = (int) ($in['mrp'] ?? 0);
                $in['perks'] = array_values(array_filter(array_map('trim', $in['perks'] ?? $out[$k]['perks'])));
                $out[$k] = array_merge($out[$k], array_intersect_key($in, $out[$k]));
            }Settings::put('plans', $out);
            Settings::audit($id, 'plans_saved');

            return $ok(['plans' => Settings::plans()]);
        }
        if ($path === 'purchases') {
            return $ok(['purchases' => DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->orderByDesc('p.created_at')->limit(500)->get(['p.*', 'u.email', 'u.name'])]);
        }
        if (in_array($path, ['overview', 'dashboard', 'users'])) {
            $days = in_array((int) $r->query('days', 30), [1, 7, 30, 90]) ? (int) $r->query('days', 30) : 30;
            $users = [];
            $usersTotal = User::count();
            $page = max(1, (int) $r->query('page', 1));
            if ($path !== 'overview') {
                $term = trim((string) $r->query('q', ''));
                $uq = User::query();
                if ($term !== '') {
                    $like = '%'.addcslashes($term, '%_\\').'%';
                    $uq->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like));
                }
                $f = (string) $r->query('f', 'all');
                if ($f === 'owner') {
                    $uq->where('is_admin', true);
                } elseif (in_array($f, ['blocked', 'paid', 'expired', 'normal'], true)) {
                    $uq->where('is_admin', false);
                    $f === 'blocked' ? $uq->where('blocked', true) : $uq->where('blocked', false);
                    $live = DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->where('provider', '!=', 'referral_reward')->select('owner_id');
                    $ever = DB::table('purchases')->whereNotNull('purchased_at')->select('owner_id');
                    match ($f) {
                        'paid' => $uq->whereIn('id', $live),
                        'expired' => $uq->whereIn('id', $ever)->whereNotIn('id', $live),
                        'normal' => $uq->whereNotIn('id', $ever),
                        default => null,
                    };
                }
                $usersTotal = (clone $uq)->count();
                $list = $uq->orderByDesc('id')->forPage($page, 100)->get(['id', 'name', 'email', 'created_at', 'blocked', 'is_admin']);
                $ids = $list->pluck('id')->all();
                $profiles = DB::table('profiles')->whereIn('owner_id', $ids)->get()->keyBy('owner_id');
                $counts = fn ($t) => DB::table($t)->whereIn('owner_id', $ids)->groupBy('owner_id')->selectRaw('owner_id, count(*) c')->pluck('c', 'owner_id');
                $resumeCounts = $counts('resume_drafts');
                $appCounts = $counts('applications');
                $everPaid = DB::table('purchases')->whereIn('owner_id', $ids)->whereNotNull('purchased_at')->distinct()->pluck('owner_id')->flip();
                $acc = Settings::accessMany($ids);
                $users = $list->map(fn ($u) => [...(array) ($profiles[$u->id] ?? []), 'owner_id' => (string) $u->id, 'email' => $u->email, 'name' => $u->name, 'created_at' => $u->created_at, 'blocked' => (bool) $u->blocked, 'isAdmin' => (bool) $u->is_admin, 'paid' => $acc[$u->id]['paid'], 'ever_paid' => isset($everPaid[$u->id]), 'last_plan' => $acc[$u->id]['plan'], 'accessUntil' => $acc[$u->id]['accessUntil'], 'resumes' => $resumeCounts[$u->id] ?? 0, 'applications' => $appCounts[$u->id] ?? 0])->values()->all();
            }
            $activity = DB::table('activity_events as a')->leftJoin('users as u', 'a.owner_id', '=', 'u.id')->where('a.created_at', '>=', now()->subDays($days))->orderByDesc('a.created_at')->limit(100)->get(['a.*', 'u.name', 'u.email']);
            $events = DB::table('activity_events')->where('created_at', '>=', now()->subDays($days))->selectRaw('event,count(*) as count')->groupBy('event')->get();
            $paid = DB::table('purchases')->where('provider', '!=', 'referral_reward')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->distinct('owner_id')->count('owner_id');
            $all = User::count();
            $data = ['users' => $users, 'usersTotal' => $usersTotal, 'page' => $page, 'perPage' => 100, 'blockedUsers' => User::where('blocked', true)->count(), 'totalUsers' => $all, 'paidUsers' => $paid, 'freeUsers' => $all - $paid, 'resumes' => DB::table('resume_drafts')->count(), 'applications' => DB::table('applications')->count(), 'activityCount' => $events->sum('count'), 'activity' => $activity, 'eventCounts' => $events, 'reviews' => DB::table('feedback')->selectRaw('status,count(*) as count')->groupBy('status')->get(), 'purchases' => DB::table('purchases')->where('created_at', '>=', now()->subDays($days))->selectRaw('status,count(*) as count,SUM(amount_paise-refunded_paise) as amount')->groupBy('status')->get(), 'daily' => DB::table('activity_events')->where('created_at', '>=', now()->subDays($days))->selectRaw('DATE(created_at) as day,event,count(*) as count')->groupByRaw('DATE(created_at),event')->orderBy('day')->get()];
            $data['totalResumes'] = $data['resumes'];
            $data['totalApplications'] = $data['applications'];
            $data['stages'] = DB::table('applications')->selectRaw('stage,count(*) as count')->groupBy('stage')->get();
            $data['expiredUsers'] = User::whereIn('id', DB::table('purchases')->whereNotNull('purchased_at')->select('owner_id'))->whereNotIn('id', DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->select('owner_id'))->count();
            if ($path === 'overview') {
                $data['users'] = $all;
            }

            return $ok($data);
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
                return $ok(['reviews' => DB::table('feedback as f')->join('users as u', 'u.id', '=', 'f.owner_id')->orderByDesc('f.created_at')->limit(200)->get(['f.*', 'u.email'])]);
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
            return $ok(['jobs' => DB::table('mail_outbox')->orderByDesc('created_at')->limit(200)->get(['id', 'recipient', 'trigger', 'subject', 'status', 'error', 'due_at', 'sent_at', 'attempts']), 'delivery' => 'Configured SMTP / Resend', 'scheduler' => 'Hostinger cron processes queued jobs.', 'heartbeat' => Settings::get('cronHeartbeat')]);
        }
        if ($path === 'email/process') {
            MailAutomation::reminders();

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
        if ($path === 'seo' || $path === 'page-audit') {
            return $ok(['origin' => config('app.url'), 'seo' => Settings::get('seo'), 'routes' => app(ContentController::class)->pages(), 'pages' => app(ContentController::class)->audit()]);
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
            validator($d, array_fill_keys(['payments', 'email', 'ai', 'analytics', 'push'], 'required|boolean'))->validate();
        }
        if ($k === 'popup') {
            validator($d, ['enabled' => 'required|boolean', 'delaySeconds' => 'required|integer|min:0|max:600', 'showSeconds' => 'required|integer|min:3|max:30', 'gapSeconds' => 'required|integer|min:3|max:120', 'useLiveActivity' => 'required|boolean', 'custom' => 'present|array|max:20', 'custom.*.title' => 'nullable|string|max:60', 'custom.*.text' => 'required|string|max:200', 'custom.*.icon' => 'nullable|string|max:8', 'custom.*.enabled' => 'required|boolean'])->validate();
            \Illuminate\Support\Facades\Cache::forget('public-activity');
        }
        if ($k === 'site') {
            validator($d, ['announce' => 'nullable|string|max:300', 'announceLink' => 'nullable|string|max:300', 'heroPill' => 'nullable|string|max:120', 'heroTitle' => 'required|string|max:200', 'heroRoles' => 'required|array|min:1|max:12', 'heroRoles.*' => 'string|max:60', 'heroLead' => 'required|string|max:600', 'ctaPrimary' => 'required|string|max:60', 'ctaSecondary' => 'required|string|max:60', 'ctaFinalTitle' => 'nullable|string|max:200', 'ctaFinalText' => 'nullable|string|max:400', 'pages' => 'present|array', 'pages.*.title' => 'nullable|string|max:180', 'pages.*.description' => 'nullable|string|max:400', 'pages.*.keywords' => 'nullable|string|max:400'])->validate();
        }
        if ($k === 'seo') {
            validator($d, ['sitemapEnabled' => 'required|boolean', 'searchIndexing' => 'required|boolean', 'siteUrl' => 'nullable|url:https|max:255', 'pages' => 'present|array'])->validate();
        }
        if ($k === 'emails') {
            foreach ($d as $t) {
                validator($t, ['id' => 'required|string|max:80', 'subject' => 'required|string|max:180', 'body' => 'required|string|max:20000', 'enabled' => 'required|boolean', 'trigger' => 'required|in:profile_created,password_reset,resume_saved,purchase_verified,abandoned_purchase,blog_published,access_expiring,followup_due,application_status,download_locked,manual', 'delayHours' => 'required|integer|min:0|max:720'])->validate();
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
