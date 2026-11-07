<?php

namespace App\Http\Controllers;

use App\Services\Growth;
use App\Services\Metrics;
use App\Services\Reconcile;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Executive numbers and payment reconciliation (3.43.0).
 *   GET  metrics?days=7|30|90       the KPIs with their definitions, when they were worked out, and owner alerts
 *   GET  reconciliation             open and recent payment mismatches and the last run
 *   POST reconciliation/run         {day: Y-m-d} run it now for one day (owner)
 *   POST reconciliation/{id}/resolve {note} mark one checked (owner)
 */
class MetricsAdminController extends Controller
{
    public const ROUTES = '#^(metrics|reconciliation(/run|/\d+/resolve)?)$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($a = []) => response()->json(['ok' => true, ...$a]);
        $get = $r->isMethod('GET');
        if ($path === 'metrics' && $get) {
            $days = in_array((int) $r->query('days', 30), [1, 7, 30, 90], true) ? (int) $r->query('days', 30) : 30;
            $to = now()->tz(Growth::TZ);
            $from = $days === 1 ? $to->copy()->startOfDay() : $to->copy()->subDays($days)->startOfDay();

            return $ok(Metrics::compute($from, $to) + ['days' => $days, 'alerts' => Metrics::alerts()]);
        }
        if ($path === 'reconciliation' && $get) {
            return $ok(['open' => DB::table('payment_reconciliation_records')->whereNull('resolved_at')->orderByDesc('day')->limit(200)->get(),
                'resolved' => DB::table('payment_reconciliation_records')->whereNotNull('resolved_at')->orderByDesc('resolved_at')->limit(50)->get(),
                'last' => Settings::get('reconcileLast', null)]);
        }
        if ($path === 'reconciliation/run' && ! $get) {
            $v = $r->validate(['day' => 'required|date_format:Y-m-d|before:today|after:'.now()->subDays(60)->toDateString()]);

            return $ok(['result' => Reconcile::run(\Carbon\Carbon::parse($v['day'], Growth::TZ))]);
        }
        if (preg_match('#^reconciliation/(\d+)/resolve$#', $path, $m) && ! $get) {
            $v = $r->validate(['note' => 'required|string|min:5|max:500']);
            Reconcile::resolve((int) $m[1], $r->user()->id, $v['note']);

            return $ok();
        }
        abort(404);
    }
}
