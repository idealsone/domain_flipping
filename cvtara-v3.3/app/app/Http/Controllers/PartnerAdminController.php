<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\AdminTable;
use App\Services\Partners;
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

            return $ok(['partner' => $p, 'members' => $members, 'kinds' => Partners::KINDS] + Partners::stats((int) $p->id));
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
