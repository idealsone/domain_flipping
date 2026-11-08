<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\ExpertReview;
use App\Services\Health;
use App\Services\MailAutomation;
use App\Services\Payments;
use App\Services\Referrals;
use App\Services\Refunds;
use App\Services\Services;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner-only operations: user management, order detail, error log, site URLs and daily health. */
class AdminOpsController extends Controller
{
    public const ROUTES = '#^(refunds(/settings|/[0-9a-f-]{36}/(approve|reject))?|services(/settings|/[0-9a-f-]{36}(/(review|resend|retry))?)?|users/(grant|update|revoke|delete)|orders/[0-9a-f-]{36}|errors(/clear|/download)?|site-urls|legal/generate|health/(history|run))$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $admin = $r->user()->id;
        $get = $r->isMethod('GET');

        if ($path === 'refunds' && $get) {
            return $ok(['requests' => Refunds::queue(), 'settings' => Refunds::settings(), 'days' => Refunds::DAYS]);
        }
        if ($path === 'refunds/settings') {
            abort_unless($r->isMethod('POST'), 405);
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['autoApprove' => 'required|boolean']);
            Settings::put('refunds', ['autoApprove' => (bool) $v['autoApprove']]);
            Settings::audit($admin, 'refund_settings_saved');

            return $ok(['settings' => Refunds::settings()]);
        }
        if (preg_match('#^refunds/([0-9a-f-]{36})/(approve|reject)$#', $path, $m)) {
            abort_unless($r->isMethod('POST'), 405);
            AccountSecurity::requireRecent($r);
            if ($m[2] === 'approve') {
                Refunds::approve($r->user(), $m[1]);
            } else {
                Refunds::reject($r->user(), $m[1], (string) $r->input('note', ''));
            }

            return $ok(['requests' => Refunds::queue()]);
        }
        if ($path === 'services' && $get) {
            return $ok(['orders' => Services::queue(), 'settings' => ExpertReview::settings(), 'dailyLimit' => Services::DAILY_LIMIT]);
        }
        if ($path === 'services/settings') {
            abort_unless($r->isMethod('POST'), 405);
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['autoReview' => 'required|boolean']);
            Settings::put('serviceSettings', ['autoReview' => (bool) $v['autoReview']]);
            Settings::audit($admin, 'service_settings_saved', $v['autoReview'] ? 'auto_on' : 'auto_off');

            return $ok(['orders' => Services::queue(), 'settings' => ExpertReview::settings()]);
        }
        if (preg_match('#^services/([0-9a-f-]{36})/(review|resend|retry)$#', $path, $m)) {
            abort_unless($r->isMethod('POST'), 405);
            match ($m[2]) {
                'review' => ExpertReview::edit($r->user(), $m[1], (string) $r->validate(['review' => 'present|nullable|string|max:60000'])['review']),
                'resend' => ExpertReview::resend($r->user(), $m[1]),
                'retry' => ExpertReview::retry($r->user(), $m[1]),
            };

            return $ok(['orders' => Services::queue(), 'settings' => ExpertReview::settings()]);
        }
        if (preg_match('#^services/([0-9a-f-]{36})$#', $path, $m)) {
            abort_unless($r->isMethod('POST'), 405);
            $v = $r->validate(['status' => 'required|in:'.implode(',', Services::STATUSES), 'note' => 'nullable|string|max:2000', 'review' => 'nullable|string|max:60000']);
            Services::update($r->user(), $m[1], $v['status'], (string) ($v['note'] ?? ''), $v['review'] ?? null);

            return $ok(['orders' => Services::queue(), 'settings' => ExpertReview::settings()]);
        }
        if (preg_match('#^orders/([0-9a-f-]{36})$#', $path, $m)) {
            abort_unless($get, 405);

            return $ok($this->orderDetail($m[1]));
        }
        if ($path === 'errors') {
            abort_unless($get, 405);

            return $ok($this->errors($r));
        }
        if ($path === 'errors/download') {
            abort_unless($get, 405);
            $rows = $this->errors($r, 5000)['errors'];
            if ($r->query('format') === 'json') {
                return response(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="cvtara-error-log-'.now()->format('Ymd-His').'.json"']);
            }
            $h = ['last_seen', 'first_seen', 'level', 'source', 'occurrences', 'message', 'location', 'url', 'resolved'];
            $csv = implode(',', $h)."\n";
            foreach ($rows as $e) {
                $csv .= implode(',', array_map(fn ($k) => '"'.str_replace('"', '""', ltrim((string) ($e[$k] ?? ''), "=+-@\t\r")).'"', $h))."\n";
            }

            return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="cvtara-error-log-'.now()->format('Ymd-His').'.csv"']);
        }
        if ($path === 'errors/clear') {
            abort_unless($r->isMethod('POST'), 405);
            $v = $r->validate(['ids' => 'sometimes|array|max:500', 'ids.*' => 'integer', 'mode' => 'required|in:resolve,delete']);
            $q = DB::table('error_logs');
            if (! empty($v['ids'])) {
                $q->whereIn('id', $v['ids']);
            }
            $n = $v['mode'] === 'delete' ? $q->delete() : $q->whereNull('resolved_at')->update(['resolved_at' => now()]);
            Settings::audit($admin, 'error_log_'.$v['mode'], (string) $n);

            return $ok(['affected' => $n]);
        }
        if ($path === 'site-urls') {
            abort_unless($get, 405);

            return $ok($this->siteUrls());
        }
        if ($path === 'legal/generate') {
            abort_unless($get, 405);
            $cur = Settings::get('legal');
            $d = Settings::defaults()['legal'];
            $email = $cur['supportEmail'] ?: 'support@cvtara.com';
            $city = trim(explode("\n", $cur['address'] ?: 'CVTara, Bangalore, Karnataka, India')[0]);
            $out = [];
            foreach (['about', 'terms', 'privacy', 'refund'] as $k) {
                $out[$k] = str_replace(['support@cvtara.com', 'CVTara, Bangalore, Karnataka, India'], [$email, $city], $d[$k]);
            }

            return $ok(['policies' => $out]);
        }
        if ($path === 'health/history') {
            abort_unless($get, 405);

            return $ok(['history' => Settings::get('healthHistory', []), 'today' => Health::snapshot()]);
        }
        if ($path === 'health/run') {
            abort_unless($r->isMethod('POST'), 405);

            return $ok(['snapshot' => Health::run(true), 'history' => Settings::get('healthHistory', [])]);
        }

        abort_unless($r->isMethod('POST'), 405);
        AccountSecurity::requireRecent($r);
        if ($path === 'users/grant') {
            $v = $r->validate(['userId' => 'required|integer', 'plan' => 'required|string|max:60', 'days' => 'nullable|integer|min:1|max:1095', 'amount' => 'nullable|numeric|min:0|max:1000000', 'note' => 'required|string|min:3|max:300', 'sendEmail' => 'sometimes|boolean']);
            $target = User::findOrFail($v['userId']);
            abort_if($target->is_admin, 422, 'Owner accounts do not need a plan.');
            $plans = Settings::plans();
            abort_unless(in_array($v['plan'], Settings::paidPlanKeys(), true) && isset($plans[$v['plan']]), 422, 'Choose one of the paid plans or add-on packs.');
            Payments::guardAddon($target, $v['plan']);
            $plan = $plans[$v['plan']];
            $paise = (int) round(((float) ($v['amount'] ?? 0)) * 100);
            $days = (int) ($v['days'] ?? ($plan['accessDays'] ?? 0));
            $id = (string) Str::uuid();
            $order = 'manual_'.str_replace('-', '', $id);
            $ref = 'manual_'.Str::lower(Str::random(10));
            DB::table('purchases')->insert(['id' => $id, 'owner_id' => $target->id, 'plan' => $v['plan'], 'amount_paise' => $paise, 'discount_paise' => 0, 'provider' => 'manual', 'provider_order_id' => $order, 'is_live' => $paise > 0, 'limits_json' => json_encode($plan), 'access_days' => $days, 'created_at' => now(), 'updated_at' => now()]);
            Payments::capture(['order_id' => $order, 'id' => $ref, 'amount' => $paise, 'currency' => 'INR', 'status' => 'captured'], 'manual');
            if (empty($v['sendEmail'] ?? true)) {
                DB::table('mail_outbox')->where('owner_id', $target->id)->where('trigger', 'purchase_verified')->where('created_at', '>=', now()->subMinute())->where('status', 'queued')->delete();
            }
            Settings::audit($admin, 'manual_grant', $id.': '.$v['note']);
            $mail = null;
            if ($v['sendEmail'] ?? true) {
                $mail = MailAutomation::process();
            }

            return $ok(['purchaseId' => $id, 'mail' => $mail, 'access' => Settings::access($target->id)]);
        }
        if ($path === 'users/update') {
            $v = $r->validate(['userId' => 'required|integer', 'name' => 'required|string|max:120', 'email' => 'required|email|max:190', 'phone' => 'nullable|string|max:40', 'profession' => 'nullable|string|max:120', 'category' => 'nullable|string|max:60', 'city' => 'nullable|string|max:120', 'experienceLevel' => 'nullable|in:fresher,experienced']);
            $target = User::findOrFail($v['userId']);
            abort_if($target->is_admin && $target->id !== $admin, 422, 'Edit other owner accounts from their own login.');
            abort_if(User::where('email', $v['email'])->where('id', '!=', $target->id)->exists(), 422, 'That email address belongs to another account.');
            DB::transaction(function () use ($target, $v, $admin) {
                $changed = mb_strtolower($v['email']) !== mb_strtolower($target->email);
                DB::table('users')->where('id', $target->id)->update(['name' => $v['name'], 'email' => mb_strtolower($v['email']), 'updated_at' => now()] + ($changed ? ['email_verified_at' => null] : []));
                $p = array_filter(['name' => $v['name'], 'phone' => $v['phone'] ?? null, 'profession' => $v['profession'] ?? null, 'category' => $v['category'] ?? null, 'city' => $v['city'] ?? null, 'experience_level' => $v['experienceLevel'] ?? null], fn ($x) => $x !== null);
                if ($p) {
                    DB::table('profiles')->where('owner_id', $target->id)->update($p + ['updated_at' => now()]);
                }
                Settings::audit($admin, 'user_updated', (string) $target->id);
            });

            return $ok();
        }
        if ($path === 'users/revoke') {
            $v = $r->validate(['userId' => 'required|integer', 'note' => 'required|string|min:5|max:300']);
            $target = User::findOrFail($v['userId']);
            $n = 0;
            DB::transaction(function () use ($target, $v, $admin, &$n) {
                foreach (DB::table('purchases')->where('owner_id', $target->id)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->lockForUpdate()->get() as $p) {
                    // Access ends now; no refund is recorded because no money moved. Real refunds arrive through the payment provider.
                    DB::table('purchases')->where('id', $p->id)->update(['access_until' => now(), 'updated_at' => now()]);
                    $n++;
                }
                Settings::audit($admin, 'subscription_revoked', $target->id.': '.$v['note']);
            });

            return $ok(['revoked' => $n]);
        }
        if ($path === 'users/delete') {
            $v = $r->validate(['userId' => 'required|integer', 'confirm' => 'required|in:DELETE']);
            $target = User::findOrFail($v['userId']);
            abort_if($target->id === $admin, 422, 'You cannot delete your own owner account.');
            app(ApiController::class)->deleteAccount($target);
            Settings::audit($admin, 'user_deleted_by_owner', (string) $target->id);

            return $ok();
        }
        abort(404);
    }

    private function orderDetail(string $id): array
    {
        $p = DB::table('purchases')->where('id', $id)->first();
        abort_unless($p, 404);
        $u = DB::table('users')->where('id', $p->owner_id)->first(['id', 'name', 'email', 'created_at']);
        $profile = DB::table('profiles')->where('owner_id', $p->owner_id)->first(['phone', 'city', 'profession', 'experience_level']);
        $limits = json_decode((string) $p->limits_json, true) ?: [];
        $timeline = DB::table('activity_events')->where(function ($q) use ($p) {
            $q->where('reference', $p->id)->orWhere('reference', (string) $p->payment_reference);
        })->orderBy('created_at')->get(['event', 'reference', 'created_at']);
        $mail = DB::table('mail_outbox')->where('owner_id', $p->owner_id)->whereIn('trigger', ['purchase_verified'])->orderByDesc('created_at')->limit(3)->get(['subject', 'status', 'sent_at', 'created_at']);

        return ['order' => $p, 'orderNo' => 'ORD-'.strtoupper(substr($p->id, 0, 6)), 'user' => $u, 'profile' => $profile, 'limits' => $limits, 'timeline' => $timeline, 'emails' => $mail, 'net' => $p->amount_paise - $p->refunded_paise];
    }

    private function errors(Request $r, int $limit = 300): array
    {
        $days = max(1, min(365, (int) $r->query('days', 30)));
        $q = DB::table('error_logs')->where('last_seen', '>=', now()->subDays($days));
        if (in_array($r->query('level'), ['error', 'warning', 'info'], true)) {
            $q->where('level', $r->query('level'));
        }
        if (in_array($r->query('source'), ['server', 'browser', 'mail', 'payment', 'cron'], true)) {
            $q->where('source', $r->query('source'));
        }
        if ($r->query('state') === 'open') {
            $q->whereNull('resolved_at');
        }
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->where('message', 'like', '%'.addcslashes($term, '%_\\').'%');
        }
        $rows = $q->orderByDesc('last_seen')->limit($limit)->get()->map(fn ($e) => (array) $e + ['resolved' => $e->resolved_at ? 'yes' : 'no'])->all();
        // failed e-mail jobs are errors too — surface them without duplicating storage
        if (! $r->query('source') || $r->query('source') === 'mail') {
            foreach (DB::table('mail_outbox')->where('status', 'failed')->where('updated_at', '>=', now()->subDays($days))->orderByDesc('updated_at')->limit(100)->get() as $m) {
                $rows[] = ['id' => 'mail-'.$m->id, 'level' => 'error', 'source' => 'mail', 'message' => 'Email "'.$m->subject.'" to '.$m->recipient.' failed: '.$m->error, 'location' => $m->trigger, 'url' => null, 'occurrences' => max(1, (int) $m->attempts), 'first_seen' => (string) $m->created_at, 'last_seen' => (string) $m->updated_at, 'resolved_at' => null, 'resolved' => 'no'];
            }
            usort($rows, fn ($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));
        }
        $count = fn ($l) => count(array_filter($rows, fn ($e) => $e['level'] === $l && empty($e['resolved_at'])));

        return ['errors' => array_slice($rows, 0, $limit), 'summary' => ['error' => $count('error'), 'warning' => $count('warning'), 'info' => $count('info'), 'total' => count($rows)]];
    }

    private function siteUrls(): array
    {
        $seo = Settings::get('seo');
        $base = rtrim($seo['siteUrl'] ?: config('app.url'), '/');
        $rows = [];
        $add = function (string $group, string $label, string $path) use (&$rows, $base) {
            $rows[] = ['group' => $group, 'label' => $label, 'path' => $path, 'url' => $base.$path];
        };
        foreach ([['Home', '/'], ['Resume templates', '/templates.html'], ['Cover letters', '/cover-letters.html'], ['Blog', '/blog.html'], ['Guides & calculators', '/guides.html'], ['Checkout', '/checkout.html'], ['Customer guide', '/customer-guide.html']] as [$l, $p]) {
            $add('Main pages', $l, $p);
        }
        foreach ([['About us', '/about.html'], ['Terms & conditions', '/terms.html'], ['Privacy policy', '/privacy.html'], ['Refund policy', '/refund.html'], ['Contact & support', '/contact.html']] as [$l, $p]) {
            $add('Legal & company', $l, $p);
        }
        foreach ([['Sign in', '/login'], ['Create account', '/register'], ['Forgot password', '/forgot-password']] as [$l, $p]) {
            $add('Account', $l, $p);
        }
        foreach (app(ContentController::class)->pages() as $p) {
            $add(str_starts_with($p['path'], '/calculators/') ? 'Calculators' : 'Guides', $p['title'], $p['path']);
        }
        $add('SEO files', 'Sitemap', '/sitemap.xml');
        $add('SEO files', 'robots.txt', '/robots.txt');
        $private = [['Customer workspace', '/workspace.html'], ['Owner console', '/admin.html'], ['Platform guide', '/admin-guide.html']];

        return ['base' => $base, 'urls' => $rows, 'private' => array_map(fn ($x) => ['label' => $x[0], 'path' => $x[1], 'url' => $base.$x[1]], $private), 'indexing' => (bool) $seo['searchIndexing']];
    }
}
