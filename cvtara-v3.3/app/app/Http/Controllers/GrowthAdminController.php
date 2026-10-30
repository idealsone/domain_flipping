<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\AdminTable;
use App\Services\Growth;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner side of the Growth page. Reached through /api/admin/growth… */
class GrowthAdminController extends Controller
{
    public const ROUTES = '#^growth(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $get = $r->isMethod('GET');
        if ($path === 'growth' && $get) {
            $now = now()->tz(Growth::TZ);
            $month = Growth::month($now);
            $today = Growth::metrics($now->copy()->startOfDay(), $now);
            $week = Growth::metrics($now->copy()->subDays(6)->startOfDay(), $now);

            return $ok([
                'month' => $month, 'today' => $today, 'week' => $week, 'stages' => Growth::STAGES,
                'bottleneck' => Growth::bottleneck($month['metrics']), 'actions' => Growth::actions($month, $today),
                'year' => Growth::yearPlan(), 'budget' => Growth::budget(), 'settings' => Growth::settings(),
                'colleges' => Growth::topColleges(), 'analytics' => (bool) (Settings::get('integrations')['analytics'] ?? false),
            ]);
        }
        if ($path === 'growth/reports' && $get) {
            return $ok(AdminTable::query($r, DB::table('growth_reports')->select(['id', 'day', 'slot', 'band', 'created_at']), [
                'filters' => ['slot' => 'slot', 'band' => 'band'], 'date' => 'created_at', 'sorts' => ['sort' => 'created_at'], 'key' => 'id',
            ]));
        }
        if ($path === 'growth/report' && $get) {
            $row = DB::table('growth_reports')->where('id', (string) $r->query('id'))->first();
            abort_unless($row, 404);

            return $ok(['report' => $row]);
        }
        abort_unless($r->isMethod('POST'), 405);
        if ($path === 'growth/report-now') {
            $v = $r->validate(['slot' => 'required|in:'.implode(',', array_keys(Growth::SLOTS))]);
            $id = Growth::sendReport($v['slot'], null, true);
            Settings::audit($r->user()->id, 'growth_report_sent', $v['slot']);

            return $ok(['id' => $id]);
        }
        if ($path === 'growth/settings') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate([
                'budgetInr' => 'required|integer|min:0|max:500000', 'stretchPct' => 'required|integer|min:100|max:500',
                'avgPaise' => 'required|integer|min:100|max:10000000', 'reports' => 'required|boolean', 'reportEmail' => 'nullable|email|max:190',
                'usdInr' => 'required|numeric|min:1|max:1000', 'inputUsdPerM' => 'required|numeric|min:0|max:1000', 'outputUsdPerM' => 'required|numeric|min:0|max:1000',
                'ladder' => 'required|array', 'ladder.*' => 'integer|min:0|max:10000000',
            ]);
            $v['ladder'] = array_intersect_key($v['ladder'], Growth::LADDER);
            $v['reportEmail'] = (string) ($v['reportEmail'] ?? '');
            Settings::put('growth', $v);
            Settings::audit($r->user()->id, 'growth_settings_saved', 'cap ₹'.$v['budgetInr']);

            return $ok(['settings' => Growth::settings()]);
        }
        abort(404);
    }
}
