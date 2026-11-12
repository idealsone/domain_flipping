<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\AdminTable;
use App\Services\PartnerOps;
use App\Services\Partners;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 3.54.0 Owner side of PartnerHub: partners and agreements, campaign links, portal members, the commission ledger and
 * partner applications. Reached through /api/admin/partners… (permissions in config/permissions.php).
 */
class PartnerAdminController extends Controller
{
    public const ROUTES = '#^partners(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $u = $r->user();
        $get = $r->isMethod('GET');
        if ($path === 'partners' && $get) {
            $d = AdminTable::query($r, DB::table('partners')->select(['id', 'name', 'kind', 'status', 'city', 'contact_name', 'contact_email', 'plan_bps', 'addon_bps', 'agreement_from', 'agreement_to', 'created_at']), [
                'search' => ['name', 'contact_email', 'city'],
                'filters' => ['kind' => 'kind', 'status' => 'status'],
                'date' => 'created_at', 'sorts' => ['added' => 'created_at', 'name' => 'name'], 'sort' => 'added', 'key' => 'id',
                'export' => ['title' => 'Partners', 'labels' => ['kind' => 'Kind', 'status' => 'Status']],
            ]);
            $ids = array_map(fn ($p) => is_array($p) ? $p['id'] : $p->id, $d['rows']);
            $signups = DB::table('users')->whereIn('acq_partner_id', $ids)->groupBy('acq_partner_id')->selectRaw('acq_partner_id, COUNT(*) n')->pluck('n', 'acq_partner_id');
            $owed = DB::table('partner_commissions')->whereIn('partner_id', $ids)->whereIn('status', ['pending', 'available'])->groupBy('partner_id')->selectRaw('partner_id, SUM(amount_paise) s')->pluck('s', 'partner_id');
            $rows = array_map(fn ($p) => (array) $p + ['signups' => (int) ($signups[((array) $p)['id']] ?? 0), 'owedPaise' => (int) ($owed[((array) $p)['id']] ?? 0)], $d['rows']);
            $counts = ['partners' => DB::table('partners')->count(), 'active' => DB::table('partners')->where('status', 'active')->count(), 'applications' => DB::table('partner_applications')->where('status', 'new')->count(),
                'availablePaise' => (int) DB::table('partner_commissions')->where('status', 'available')->sum('amount_paise'), 'pendingPaise' => (int) DB::table('partner_commissions')->where('status', 'pending')->sum('amount_paise')];

            return $ok(['partners' => $rows, 'kinds' => Partners::KINDS, 'counts' => $counts] + $d);
        }
        if (preg_match('#^partners/(\d+)$#', $path, $m) && $get) {
            $p = DB::table('partners')->where('id', (int) $m[1])->first();
            abort_unless($p, 404);
            $members = DB::table('partner_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.partner_id', $p->id)->get(['m.user_id', 'm.role', 'u.name', 'u.email']);

            return $ok(['partner' => $p, 'members' => $members, 'kinds' => Partners::KINDS, 'draft' => PartnerOps::draftAgreement($p), 'durations' => PartnerOps::DURATIONS] + Partners::stats((int) $p->id));
        }
        if (preg_match('#^partners/(\d+)/portal$#', $path, $m) && $get) {
            // View as partner: the partner's own portal, read-only, logged.
            Settings::audit($u->id, 'partner_portal_viewed', $m[1]);

            return $ok(Partners::portalFor((int) $m[1], 'viewer'));
        }
        if ($path === 'partners/payouts' && $get) {
            $d = AdminTable::query($r, DB::table('partner_payouts as x')->join('partners as p', 'p.id', '=', 'x.partner_id')->select(['x.id', 'x.partner_id', 'p.name as partner', 'x.amount_paise', 'x.status', 'x.reference', 'x.note', 'x.created_at', 'x.decided_at']), [
                'search' => ['p.name', 'x.reference'], 'filters' => ['status' => 'x.status'], 'date' => 'x.created_at', 'sorts' => ['date' => 'x.created_at'], 'sort' => 'date', 'key' => 'x.id',
                'export' => ['title' => 'Partner payouts', 'labels' => ['status' => 'Status']],
            ]);

            return $ok(['payouts' => $d['rows'], 'settings' => ['attribution' => Partners::rule()]] + $d);
        }
        if ($path === 'partners/rewards' && $get) {
            $d = AdminTable::query($r, DB::table('partner_rewards as x')->join('partners as p', 'p.id', '=', 'x.partner_id')->select(['x.id', 'x.partner_id', 'p.name as partner', 'x.title', 'x.cost_paise', 'x.status', 'x.address', 'x.courier', 'x.tracking', 'x.note', 'x.created_at']), [
                'search' => ['p.name', 'x.title'], 'filters' => ['status' => 'x.status'], 'date' => 'x.created_at', 'sorts' => ['date' => 'x.created_at'], 'sort' => 'date', 'key' => 'x.id',
                'export' => ['title' => 'Partner rewards', 'labels' => ['status' => 'Status']],
            ]);

            return $ok(['rewards' => $d['rows']] + $d);
        }
        if ($path === 'partners/rankings' && $get) {
            $month = (string) ($r->query('month') ?: DB::table('partner_rankings')->max('month'));
            $rows = DB::table('partner_rankings as k')->join('partners as p', 'p.id', '=', 'k.partner_id')->where('k.month', $month)->orderBy('k.rank')->get(['k.partner_id', 'p.name', 'k.score', 'k.rank', 'k.metrics_json']);

            return $ok(['month' => $month, 'months' => DB::table('partner_rankings')->distinct()->orderByDesc('month')->limit(24)->pluck('month'), 'rankings' => $rows->map(fn ($x) => ['partnerId' => $x->partner_id, 'name' => $x->name, 'score' => $x->score, 'rank' => $x->rank] + (json_decode($x->metrics_json, true) ?: []))]);
        }
        if ($path === 'partners/commissions' && $get) {
            $d = AdminTable::query($r, DB::table('partner_commissions as c')->join('partners as p', 'p.id', '=', 'c.partner_id')->select(['c.id', 'c.partner_id', 'p.name as partner', 'c.purchase_id', 'c.plan', 'c.kind', 'c.base_paise', 'c.rate_bps', 'c.amount_paise', 'c.status', 'c.available_at', 'c.paid_at', 'c.payout_ref', 'c.void_reason', 'c.created_at']), [
                'search' => ['p.name', 'c.payout_ref'],
                'filters' => ['status' => 'c.status', 'partner' => 'c.partner_id'],
                'date' => 'c.created_at', 'sorts' => ['date' => 'c.created_at', 'amount' => 'c.amount_paise'], 'sort' => 'date', 'key' => 'c.id',
                'export' => ['title' => 'Partner commissions', 'labels' => ['status' => 'Status', 'partner' => 'Partner']],
            ]);

            return $ok(['commissions' => $d['rows']] + $d);
        }
        if ($path === 'partners/applications' && $get) {
            $d = AdminTable::query($r, DB::table('partner_applications')->select(['id', 'org_name', 'kind', 'contact_name', 'email', 'phone', 'city', 'size', 'message', 'status', 'partner_id', 'review_note', 'created_at']), [
                'search' => ['org_name', 'email', 'city'],
                'filters' => ['status' => 'status', 'kind' => 'kind'],
                'date' => 'created_at', 'sorts' => ['date' => 'created_at'], 'sort' => 'date', 'key' => 'id',
                'export' => ['title' => 'Partner applications', 'labels' => ['status' => 'Status', 'kind' => 'Kind']],
            ]);

            return $ok(['applications' => $d['rows']] + $d);
        }
        abort_unless($r->isMethod('POST'), 405);
        $partnerRules = ['name' => 'required|string|min:2|max:160', 'kind' => ['required', Rule::in(array_keys(Partners::KINDS))], 'status' => 'nullable|in:active,paused,ended',
            'contactName' => 'nullable|string|max:120', 'contactEmail' => 'nullable|email|max:190', 'contactPhone' => 'nullable|regex:/^[0-9+ -]{7,20}$/', 'city' => 'nullable|string|max:80',
            'planPercent' => 'nullable|numeric|min:0|max:60', 'addonPercent' => 'nullable|numeric|min:0|max:60', 'agreementRef' => 'nullable|string|max:120',
            'agreementFrom' => 'nullable|date', 'agreementTo' => 'nullable|date|after_or_equal:agreementFrom', 'notes' => 'nullable|string|max:2000'];
        if ($path === 'partners/save') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate($partnerRules + ['id' => 'nullable|integer']);

            return $ok(['id' => Partners::save($u, $v, isset($v['id']) ? (int) $v['id'] : null)]);
        }
        if (preg_match('#^partners/(\d+)/links$#', $path, $m)) {
            $v = $r->validate(['code' => 'nullable|string|max:40', 'campaign' => 'nullable|string|max:80', 'label' => 'nullable|string|max:120']);

            return $ok(['code' => Partners::addLink($u, (int) $m[1], $v)]);
        }
        if (preg_match('#^partners/links/(\d+)/campaign$#', $path, $m)) {
            $v = $r->validate(['seats' => 'nullable|integer|min:1|max:100000', 'target' => 'nullable|integer|min:0|max:100000', 'startsOn' => 'nullable|date', 'days' => 'nullable|in:'.implode(',', PartnerOps::DURATIONS),
                'extendDays' => 'nullable|integer|min:1|max:90', 'action' => 'nullable|in:pause,resume,close']);
            PartnerOps::saveCampaign($u, (int) $m[1], $v);

            return $ok();
        }
        if (preg_match('#^partners/(\d+)/agreements$#', $path, $m)) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['title' => 'nullable|string|max:160', 'body' => 'nullable|string|min:200|max:40000']);

            return $ok(PartnerOps::sendAgreement($u, (int) $m[1], $v['body'] ?? null, $v['title'] ?? null));
        }
        if (preg_match('#^partners/payouts/(\d+)$#', $path, $m)) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['decision' => 'required|in:paid,rejected', 'reference' => 'required_if:decision,paid|nullable|string|min:3|max:120', 'note' => 'nullable|string|max:500']);
            PartnerOps::decidePayout($u, (int) $m[1], $v['decision'], (string) ($v['reference'] ?? ''), (string) ($v['note'] ?? ''));

            return $ok();
        }
        if ($path === 'partners/rewards/save') {
            $v = $r->validate(['id' => 'nullable|integer', 'partnerId' => 'required_without:id|nullable|integer', 'title' => 'required_without:id|nullable|string|max:160', 'costInr' => 'nullable|numeric|min:0|max:1000000',
                'status' => 'nullable|in:approved,address,dispatched,delivered,cancelled', 'address' => 'nullable|string|max:600', 'courier' => 'nullable|string|max:80', 'tracking' => 'nullable|string|max:120', 'note' => 'nullable|string|max:500']);

            return $ok(['id' => PartnerOps::saveReward($u, $v)]);
        }
        if ($path === 'partners/rank') {
            $v = $r->validate(['month' => 'nullable|date_format:Y-m']);

            return $ok(['ranked' => PartnerOps::rank($v['month'] ?? null)]);
        }
        if ($path === 'partners/settings') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['attribution' => 'required|in:first,last']);
            Settings::put('partnerProgram', array_merge((array) Settings::get('partnerProgram', []), $v));
            Settings::audit($u->id, 'partner_settings', $v['attribution']);

            return $ok(['settings' => $v]);
        }
        if (preg_match('#^partners/links/(\d+)$#', $path, $m)) {
            $v = $r->validate(['active' => 'required|boolean']);
            abort_unless(DB::table('partner_links')->where('id', (int) $m[1])->update(['active' => $v['active'], 'updated_at' => now()]), 404);

            return $ok();
        }
        if (preg_match('#^partners/(\d+)/members$#', $path, $m)) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['email' => 'required|email|max:190', 'role' => 'required|in:manager,viewer', 'remove' => 'nullable|boolean']);
            if (! empty($v['remove'])) {
                DB::table('partner_members')->where('partner_id', (int) $m[1])->whereIn('user_id', DB::table('users')->where('email', strtolower($v['email']))->select('id'))->delete();
            } else {
                Partners::addMember($u, (int) $m[1], $v['email'], $v['role']);
            }

            return $ok();
        }
        if ($path === 'partners/commissions/pay') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'reference' => 'required|string|min:3|max:120']);

            return $ok(['paid' => Partners::markPaid($u, $v['ids'], $v['reference'])]);
        }
        if (preg_match('#^partners/applications/(\d+)$#', $path, $m)) {
            $v = $r->validate(['decision' => 'required|in:approved,declined', 'note' => 'nullable|string|max:500']);

            return $ok(['partnerId' => Partners::decide($u, (int) $m[1], $v['decision'], (string) ($v['note'] ?? ''))]);
        }
        abort(404);
    }
}
