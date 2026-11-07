<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval Centre (3.42.0): one list of everything waiting for a person, from every source, each decided by the source's
 * own code (so every existing rule still applies): SEO changes (SeoAgent::decide), refunds (Refunds), Business assistant
 * actions (TaraOwner) and agent / team tasks (AgentTasks). The permission per kind is in config/approvals.php. Every
 * decision is stored in approval_events and the audit log.
 */
class Approvals
{
    public static function categories(): array
    {
        return (array) config('approvals.categories', []);
    }

    public static function canDecide(User $u, string $category): bool
    {
        $c = self::categories()[$category] ?? null;

        return $c && Access::can($u, (string) $c['permission']);
    }

    /** Everything waiting that this person may decide, newest first, in one shape. */
    public static function pending(User $u): array
    {
        $out = [];
        $hours = (int) config('approvals.waitingHours', 48);
        $item = fn (string $cat, string $id, array $f) => $f + ['category' => $cat, 'categoryLabel' => self::categories()[$cat]['label'], 'id' => $id,
            'stepUp' => (bool) self::categories()[$cat]['stepUp'], 'waitingLong' => isset($f['createdAt']) && strtotime((string) $f['createdAt']) < time() - $hours * 3600];
        if (self::canDecide($u, 'seo') && Schema::hasTable('seo_proposals')) {
            foreach (DB::table('seo_proposals')->where('status', 'pending')->orderByDesc('created_at')->limit(100)->get() as $p) {
                $d = json_decode((string) $p->data_json, true) ?: [];
                $b = json_decode((string) $p->before_json, true) ?: [];
                $out[] = $item('seo', $p->id, ['submittedBy' => 'SEO agent', 'agent' => 'SEO', 'action' => $p->kind === 'meta' ? 'Change the title and description of '.$p->path : 'Add an internal link to '.$p->path,
                    'justification' => (string) $p->reason, 'cost' => 'None', 'impact' => $p->impressions ? $p->impressions.' Google impressions in 28 days, position '.round((float) $p->position, 1) : 'Not measured yet',
                    'risk' => 'Low: can be undone from SEO with one click', 'changes' => ['before' => $b, 'after' => $d], 'evidence' => array_values(array_filter([$p->query ? 'Search: '.$p->query : null])), 'createdAt' => (string) $p->created_at]);
            }
        }
        if (self::canDecide($u, 'refund')) {
            foreach (DB::table('refund_requests as r')->leftJoin('users as u', 'u.id', '=', 'r.owner_id')->leftJoin('purchases as p', 'p.id', '=', 'r.purchase_id')->where('r.status', 'requested')
                ->orderBy('r.created_at')->limit(100)->get(['r.id', 'r.amount_paise', 'r.reason', 'r.created_at', 'u.email', 'p.plan', 'p.purchased_at']) as $r) {
                $out[] = $item('refund', $r->id, ['submittedBy' => (string) $r->email, 'agent' => 'Customer', 'action' => 'Refund ₹'.number_format($r->amount_paise / 100).' for '.($r->plan ?: 'a plan'),
                    'justification' => (string) ($r->reason ?: 'No reason given'), 'cost' => '₹'.number_format($r->amount_paise / 100), 'impact' => 'The customer\'s plan ends when the refund is approved',
                    'risk' => 'Money leaves the account; checked again for use since the request', 'changes' => null, 'evidence' => ['Bought '.($r->purchased_at ?: '—')], 'createdAt' => (string) $r->created_at]);
            }
        }
        if (self::canDecide($u, 'tara') && Schema::hasTable('tara_admin_actions')) {
            foreach (DB::table('tara_admin_actions')->where('status', 'pending')->where('admin_id', $u->id)->where('created_at', '>', now()->subDay())->orderByDesc('created_at')->limit(50)->get() as $a) {
                $out[] = $item('tara', $a->id, ['submittedBy' => 'Business assistant (asked by you)', 'agent' => 'Business assistant', 'action' => (string) $a->summary, 'justification' => 'You asked for it in the Business assistant',
                    'cost' => '—', 'impact' => (string) $a->target, 'risk' => 'Checked again before it runs', 'changes' => ['before' => json_decode((string) $a->before_json, true), 'after' => json_decode((string) $a->after_json, true)], 'evidence' => [], 'createdAt' => (string) $a->created_at]);
            }
        }
        if (Schema::hasTable('ai_tasks')) {
            foreach (DB::table('ai_tasks as t')->leftJoin('users as c', 'c.id', '=', 't.created_by')->whereIn('t.status', ['submitted', 'under_review', 'completed'])->orderByDesc('t.updated_at')->limit(200)->get(['t.*', 'c.name as creator']) as $t) {
                $cat = 'task_'.$t->agent;
                if (! self::canDecide($u, $cat) || ($t->created_by && (int) $t->created_by === $u->id && ! Access::isOwner($u))) {
                    continue;
                }
                $ev = DB::table('ai_task_evidence')->where('task_id', $t->id)->orderBy('id')->pluck('value')->all();
                $out[] = $item($cat, $t->id, ['submittedBy' => $t->creator ?: (AgentTasks::AGENTS[$t->agent] ?? $t->agent).' agent', 'agent' => AgentTasks::AGENTS[$t->agent] ?? $t->agent,
                    'action' => ($t->status === 'completed' ? 'Verify: ' : 'Approve: ').$t->title, 'justification' => (string) $t->detail, 'cost' => '—', 'impact' => 'Priority '.$t->priority, 'risk' => $t->status === 'completed' ? 'Verifying needs evidence' : 'Work starts only after approval',
                    'changes' => null, 'evidence' => $ev, 'createdAt' => (string) $t->updated_at, 'verify' => $t->status === 'completed']);
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['createdAt'], $a['createdAt']));

        return $out;
    }

    public static function counts(User $u): array
    {
        $p = self::pending($u);

        return ['total' => count($p), 'waitingLong' => count(array_filter($p, fn ($x) => $x['waitingLong']))];
    }

    /** Decides one item through its own source. Returns the new status of the item. */
    public static function decide(Request $r, string $category, string $id, string $decision, string $reason): string
    {
        $u = $r->user();
        abort_unless(isset(self::categories()[$category]), 422, 'Unknown kind of approval.');
        abort_unless(self::canDecide($u, $category), 403, 'Your role cannot decide this kind of approval.');
        abort_if($decision === 'reject' && mb_strlen(trim($reason)) < 5, 422, 'Give a short reason for rejecting.');
        if (self::categories()[$category]['stepUp']) {
            AccountSecurity::requireRecent($r);
        }
        $summary = '';
        if ($category === 'seo') {
            $p = SeoAgent::decide($id, $decision);
            $status = $p->status;
            $summary = $p->kind.' '.$p->path;
        } elseif ($category === 'refund') {
            $decision === 'approve' ? Refunds::approve($u, $id) : Refunds::reject($u, $id, $reason);
            $status = (string) DB::table('refund_requests')->where('id', $id)->value('status');
            $summary = 'refund '.$id;
        } elseif ($category === 'tara') {
            $card = $decision === 'approve' ? TaraOwner::confirm($r, $id) : TaraOwner::cancel($u, $id);
            $status = (string) ($card['status'] ?? DB::table('tara_admin_actions')->where('id', $id)->value('status'));
            $summary = (string) DB::table('tara_admin_actions')->where('id', $id)->value('summary');
        } else {
            $t = DB::table('ai_tasks')->where('id', $id)->first();
            abort_unless($t && 'task_'.$t->agent === $category, 404);
            $to = $t->status === 'completed' ? ($decision === 'approve' ? 'verified' : 'in_progress') : ($decision === 'approve' ? 'approved' : 'rejected');
            $status = $to === 'in_progress' ? AgentTasks::move($u, $id, 'in_progress', 'Not verified: '.$reason)->status : AgentTasks::decide($u, $id, $to, $reason)->status;
            $summary = $t->title;
        }
        DB::table('approval_events')->insert(['source' => $category, 'item_id' => $id, 'decision' => $decision, 'reason' => mb_substr(trim($reason), 0, 1000), 'decided_by' => $u->id, 'summary' => mb_substr($summary, 0, 300), 'created_at' => now()]);
        Audit::record($u, 'approval:'.$category.':'.$decision, $id, null, ['status' => $status], $reason, 200, $r);

        return $status;
    }

    public static function history(User $u, int $limit = 100): array
    {
        $cats = array_values(array_filter(array_keys(self::categories()), fn ($c) => self::canDecide($u, $c)));

        return DB::table('approval_events as e')->leftJoin('users as u', 'u.id', '=', 'e.decided_by')->whereIn('e.source', $cats ?: ['-'])->orderByDesc('e.id')->limit($limit)
            ->get(['e.source', 'e.item_id', 'e.decision', 'e.reason', 'e.summary', 'e.created_at', 'u.name as by'])->map(fn ($e) => (array) $e + ['categoryLabel' => self::categories()[$e->source]['label'] ?? $e->source])->all();
    }
}
