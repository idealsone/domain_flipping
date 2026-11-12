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
        // 3.61.0 Test payment review: orders up to 9 Nov with a suggested class; the owner marks each test or real.
        if ($path === 'costs/test-payments' && $get) {
            $rows = \App\Services\TestPayments::rows();

            return $ok(\App\Services\AdminTable::rows($r, $rows, ['search' => ['email', 'id', 'reference', 'plan'], 'filters' => ['suggested' => 'suggested', 'mark' => 'mark', 'live' => fn ($x, $v) => ($x['live'] ? '1' : '0') === $v],
                'sorts' => ['created' => 'createdAt', 'amount' => 'amountPaise'], 'sort' => 'created', 'dir' => 'asc', 'key' => 'id',
                'export' => ['title' => 'Test payment review', 'map' => fn ($rows) => array_map(fn ($x) => ['Order' => $x['id'], 'Created' => $x['createdAt'], 'Customer' => $x['email'], 'Plan' => $x['plan'], 'Amount (₹)' => $x['amountPaise'] / 100,
                    'Refunded (₹)' => $x['refundedPaise'] / 100, 'Status' => $x['status'], 'Gateway' => $x['provider'], 'Live mode' => $x['live'] ? 'Yes' : 'No', 'Suggested' => \App\Services\TestPayments::CLASSES[$x['suggested']] ?? $x['suggested'],
                    'Why' => $x['reasons'], 'Your mark' => $x['mark'], 'Note' => $x['note']], $rows)]]) + ['summary' => \App\Services\TestPayments::summary($rows), 'classes' => \App\Services\TestPayments::CLASSES]);
        }
        if ($path === 'costs/test-payments/mark' && ! $get) {
            $v = $r->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'string|max:36', 'mark' => 'required|in:test,real,clear', 'note' => 'nullable|string|max:300']);

            return $ok(['marked' => \App\Services\TestPayments::mark($u, $v['ids'], $v['mark'], $v['note'] ?? null)]);
        }
        abort(404);
    }
}
