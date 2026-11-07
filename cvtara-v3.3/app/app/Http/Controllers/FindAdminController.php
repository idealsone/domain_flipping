<?php

namespace App\Http\Controllers;

use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Console search behind Ctrl+K (3.39.0): GET /api/admin/find?q=. Returns at most 5 customers, orders and support
 * requests, each kind only when the signed-in person's role may see it. Read-only; the palette only opens views.
 */
class FindAdminController extends Controller
{
    public const ROUTES = '#^find$#';

    public function run(Request $r, string $path)
    {
        abort_unless($r->isMethod('GET'), 405);
        $u = $r->user();
        $q = trim(mb_substr((string) $r->query('q', ''), 0, 80));
        $out = ['users' => [], 'orders' => [], 'tickets' => []];
        if (mb_strlen($q) < 2) {
            return response()->json(['ok' => true, 'q' => $q] + $out);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $start = addcslashes($q, '%_\\').'%';
        if (Access::can($u, 'customers.view')) {
            $out['users'] = DB::table('users')->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like)->orWhere('id', ctype_digit($q) ? (int) $q : -1))
                ->orderByDesc('id')->limit(5)->get(['id', 'name', 'email', 'is_admin', 'blocked'])
                ->map(fn ($x) => ['id' => (int) $x->id, 'name' => (string) $x->name, 'email' => (string) $x->email, 'owner' => (bool) $x->is_admin, 'blocked' => (bool) $x->blocked])->all();
        }
        if (Access::can($u, 'orders.view')) {
            $out['orders'] = DB::table('purchases as p')->leftJoin('users as u', 'u.id', '=', 'p.owner_id')
                ->where(fn ($w) => $w->where('p.id', 'like', $start)->orWhere('p.provider_order_id', 'like', $start)->orWhere('p.payment_reference', 'like', $start)->orWhere('u.email', 'like', $like))
                ->orderByDesc('p.created_at')->limit(5)->get(['p.id', 'p.plan', 'p.amount_paise', 'p.status', 'p.created_at', 'u.email'])
                ->map(fn ($x) => ['id' => (string) $x->id, 'plan' => (string) $x->plan, 'amountPaise' => (int) $x->amount_paise, 'status' => (string) $x->status, 'at' => (string) $x->created_at, 'email' => (string) $x->email])->all();
        }
        if (Access::can($u, 'support.view')) {
            $out['tickets'] = DB::table('support_reports')->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like)->orWhere('message', 'like', $like)->orWhere('id', 'like', $start))
                ->orderByDesc('created_at')->limit(5)->get(['id', 'email', 'kind', 'status', 'message', 'created_at'])
                ->map(fn ($x) => ['id' => (string) $x->id, 'email' => (string) $x->email, 'kind' => (string) $x->kind, 'status' => (string) $x->status, 'text' => mb_strimwidth((string) $x->message, 0, 90, '…'), 'at' => (string) $x->created_at])->all();
        }

        return response()->json(['ok' => true, 'q' => $q] + $out);
    }
}
