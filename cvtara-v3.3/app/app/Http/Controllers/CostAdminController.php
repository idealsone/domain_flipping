<?php

namespace App\Http\Controllers;

use App\Services\CostControls;
use Illuminate\Http\Request;

/**
 * 3.56.0 Costs & margin (V7 §24-§26): AI caps and alerts, the provider invoice check and the contribution margin per
 * plan and add-on. Reached through /api/admin/costs… Owner only (not listed in config/permissions.php routes), except
 * reading, which finance roles with orders.view may do.
 */
class CostAdminController extends Controller
{
    public const ROUTES = '#^costs(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $u = $r->user();
        $get = $r->isMethod('GET');
        if ($path === 'costs' && $get) {
            return $ok(CostControls::overview());
        }
        if ($path === 'costs/margin' && $get) {
            $tz = CostControls::TZ;
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('to')) ? (string) $r->query('to') : now()->tz($tz)->toDateString();
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('from')) ? (string) $r->query('from') : now()->tz($tz)->subDays(29)->toDateString();
            abort_if($from > $to, 422, 'The From date is after the To date.');

            return $ok(['rows' => CostControls::margin($from, $to), 'range' => ['from' => $from, 'to' => $to], 'settings' => CostControls::settings()]);
        }
        if ($path === 'costs/settings' && ! $get) {
            return $ok(['settings' => CostControls::save($u, (array) $r->json()->all())]);
        }
        if ($path === 'costs/invoice' && ! $get) {
            CostControls::saveInvoice($u, (string) $r->input('month'), (float) $r->input('amountInr'), (string) $r->input('note', ''));

            return $ok(['invoices' => CostControls::reconcile()]);
        }
        abort(404);
    }
}
