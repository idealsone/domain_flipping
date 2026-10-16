<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\BusinessControls;
use App\Services\Promos;
use App\Services\Settings;
use Illuminate\Http\Request;

/** Owner side of "Offers & festivals". Reached through /api/admin/promos… */
class PromoAdminController extends Controller
{
    public const ROUTES = '#^promos(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        if ($path === 'promos' && $r->isMethod('GET')) {
            return $ok($this->snapshot());
        }
        abort_unless($r->isMethod('POST'), 405);
        AccountSecurity::requireRecent($r);
        if ($path === 'promos/employers') {
            $v = $r->validate(['enabled' => 'required|boolean', 'heading' => 'required|string|max:120', 'names' => 'array|max:30', 'names.*' => 'string|max:40']);
            $site = (array) Settings::get('site');
            $site['employers'] = ['enabled' => (bool) $v['enabled'], 'heading' => $v['heading'], 'names' => array_values(array_filter(array_map('trim', $v['names'] ?? [])))];
            Settings::put('site', $site);
            Settings::audit($id, 'employer_names_saved', count($site['employers']['names']).' names');

            return $ok(['employers' => $site['employers']]);
        }
        $list = Promos::all();
        if ($path === 'promos/save') {
            $v = $r->validate([
                'id' => ['nullable', 'regex:/^[a-f0-9-]{36}$/'], 'name' => 'required|string|max:80', 'template' => 'nullable|string|max:30', 'theme' => 'required|in:'.implode(',', array_keys(Promos::THEMES)),
                'heading' => 'required|string|max:120', 'subheading' => 'nullable|string|max:240', 'body' => 'nullable|string|max:500', 'badge' => 'nullable|string|max:40', 'cta' => 'nullable|string|max:40',
                'couponCode' => 'nullable|string|max:30|regex:/^[A-Za-z0-9_-]*$/', 'planKey' => 'nullable|string|max:60', 'imageUrl' => ['nullable', 'string', 'max:300', 'regex:#^(https://|/media/[a-f0-9-]{36}\.webp$)#'],
                'startsAt' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/'], 'endsAt' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/'], 'countdown' => 'boolean',
                'banner' => 'boolean', 'popup' => 'boolean', 'popupDelay' => 'integer|min:3|max:600', 'exit' => 'boolean', 'idle' => 'boolean', 'idleSeconds' => 'integer|min:10|max:600',
                'exitHeading' => 'nullable|string|max:120', 'exitText' => 'nullable|string|max:240', 'idleHeading' => 'nullable|string|max:120', 'idleText' => 'nullable|string|max:240',
                'pages' => 'required|in:all,home,home-pricing', 'frequencyHours' => 'integer|min:1|max:720', 'priority' => 'integer|min:0|max:100',
            ]);
            $v['couponCode'] = strtoupper($v['couponCode'] ?? '');
            if ($v['couponCode'] !== '') {
                abort_unless(Promos::coupon($v['couponCode']), 422, 'That coupon does not exist. Create it first.');
            }
            if (! empty($v['planKey'])) {
                abort_unless(in_array($v['planKey'], Settings::paidPlanKeys(), true), 422, 'Choose a paid plan or leave the plan empty.');
            }
            if (! empty($v['startsAt']) && ! empty($v['endsAt'])) {
                abort_if(strtotime($v['endsAt']) <= strtotime($v['startsAt']), 422, 'The end must be after the start.');
            }
            $eid = (string) ($v['id'] ?? '');
            $new = $eid !== '' ? collect($list)->firstWhere('id', $eid) : null;
            $c = array_merge($new ?? ['mode' => 'off'], $v, ['id' => $eid !== '' ? $eid : Promos::newId()]);
            $list = collect($list)->reject(fn ($x) => $x['id'] === $c['id'])->push($c)->values()->all();
            Promos::put($list);
            Settings::audit($id, 'promo_saved', $c['id']);

            return $ok($this->snapshot() + ['id' => $c['id']]);
        }
        $cid = (string) $r->input('id');
        abort_unless(collect($list)->contains('id', $cid) || $path === 'promos/coupon', 404);
        if ($path === 'promos/live') {
            // One live campaign at a time: switching is a single click.
            $list = array_map(fn ($x) => $x['id'] === $cid ? array_merge($x, ['mode' => 'live']) : ($x['mode'] === 'live' ? array_merge($x, ['mode' => 'off']) : $x), $list);
            Promos::put($list);
            Settings::audit($id, 'promo_live', $cid);
        } elseif ($path === 'promos/schedule') {
            $c = collect($list)->firstWhere('id', $cid);
            abort_if(empty($c['startsAt']) && empty($c['endsAt']), 422, 'Set a start or end date first.');
            Promos::put(array_map(fn ($x) => $x['id'] === $cid ? array_merge($x, ['mode' => 'scheduled']) : $x, $list));
            Settings::audit($id, 'promo_scheduled', $cid);
        } elseif ($path === 'promos/off') {
            Promos::put(array_map(fn ($x) => $x['id'] === $cid ? array_merge($x, ['mode' => 'off']) : $x, $list));
            Settings::audit($id, 'promo_off', $cid);
        } elseif ($path === 'promos/delete') {
            Promos::put(array_values(array_filter($list, fn ($x) => $x['id'] !== $cid)));
            Settings::audit($id, 'promo_deleted', $cid);
        } elseif ($path === 'promos/coupon') {
            $v = $r->validate(['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]{3,30}$/'], 'type' => 'required|in:percent,flat', 'value' => 'required|integer|min:1|max:100000', 'plans' => 'required|array|min:1', 'plans.*' => 'in:'.implode(',', Settings::paidPlanKeys()), 'expiresAt' => 'nullable|date_format:Y-m-d', 'maxUses' => 'nullable|integer|min:0|max:1000000', 'perUser' => 'nullable|integer|min:0|max:100']);
            abort_if($v['type'] === 'percent' && $v['value'] > 90, 422, 'Percentage discounts cannot exceed 90%.');
            $bc = (array) Settings::get('business_controls', []);
            $coupons = collect($bc['coupons'] ?? [])->reject(fn ($c) => strtoupper($c['code']) === strtoupper($v['code']))->push(['code' => strtoupper($v['code']), 'type' => $v['type'], 'value' => (int) $v['value'], 'plans' => array_values($v['plans']), 'expiresAt' => $v['expiresAt'] ?? '', 'enabled' => true, 'maxUses' => (int) ($v['maxUses'] ?? 0), 'perUser' => (int) ($v['perUser'] ?? 1)])->values()->all();
            Settings::put('business_controls', array_merge($bc, ['coupons' => $coupons]));
            Promos::bump();
            Settings::audit($id, 'coupon_saved', strtoupper($v['code']));
        } else {
            abort(404);
        }

        return $ok($this->snapshot());
    }

    private function snapshot(): array
    {
        $stats = Promos::stats();
        $now = time();
        $campaigns = array_map(fn ($c) => $c + ['status' => Promos::status($c, $now), 'stats' => $stats[$c['id']], 'view' => Promos::view($c), 'couponOk' => ($c['couponCode'] ?? '') === '' || Promos::couponUsable(Promos::coupon($c['couponCode']))], Promos::all());
        usort($campaigns, fn ($a, $b) => [$a['status'] === 'live' ? 0 : 1, $a['name']] <=> [$b['status'] === 'live' ? 0 : 1, $b['name']]);
        $plans = [];
        foreach (Settings::plans() as $k => $p) {
            if (($p['type'] ?? '') === 'plan' && in_array($k, Settings::paidPlanKeys(), true)) {
                $plans[] = ['key' => $k, 'name' => $p['name'], 'price' => $p['price']];
            }
        }

        return [
            'campaigns' => $campaigns,
            'employers' => ((array) Settings::get('site'))['employers'] ?? ['enabled' => false, 'heading' => '', 'names' => []],
            'templates' => collect(Promos::TEMPLATES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'heading' => $t[1], 'subheading' => $t[2], 'badge' => $t[3], 'theme' => $t[4]])->values()->all(),
            'themes' => collect(Promos::THEMES)->map(fn ($t, $k) => ['key' => $k, 'from' => $t[0], 'to' => $t[1], 'ink' => $t[2], 'accent' => $t[3], 'art' => $t[4]])->values()->all(),
            'coupons' => array_values(BusinessControls::get()['coupons']),
            'plans' => $plans,
            'siteUrl' => rtrim((string) config('app.url'), '/'),
        ];
    }
}
