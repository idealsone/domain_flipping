<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agent and team tasks (3.42.0). Lifecycle: Draft → Submitted → Under review → Approved → In progress → Completed →
 * Verified, plus Rejected, Blocked, Overdue, Failed and Cancelled. Rules kept here, not in the screens:
 *  - Approved and Rejected are decided in the Approval Centre by someone with the approve permission of that agent,
 *    and never by the person who created the task.
 *  - Verified needs at least one piece of evidence and a reviewer other than the person who did the work.
 *  - Every move is stored with who made it and why.
 * The SEO and Growth agents create tasks from their daily findings (one open task per finding).
 */
class AgentTasks
{
    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'approved' => 'Approved', 'in_progress' => 'In progress',
        'completed' => 'Completed', 'verified' => 'Verified', 'rejected' => 'Rejected', 'blocked' => 'Blocked', 'overdue' => 'Overdue', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

    public const AGENTS = ['seo' => 'SEO', 'growth' => 'Growth', 'staff' => 'Team'];

    /** Moves a person may make with the agent's draft permission. Approve, reject and verify are not here on purpose. */
    private const MOVES = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['under_review', 'cancelled'],
        'under_review' => ['blocked', 'cancelled'],
        'approved' => ['in_progress', 'blocked', 'cancelled'],
        'in_progress' => ['completed', 'blocked', 'failed'],
        'blocked' => ['in_progress', 'cancelled'],
        'overdue' => ['in_progress', 'completed', 'failed', 'cancelled'],
        'completed' => ['in_progress'],
        'failed' => ['in_progress', 'cancelled'],
    ];

    /** Moves that need the agent's approve permission. */
    private const DECISIONS = ['submitted' => ['approved', 'rejected'], 'under_review' => ['approved', 'rejected'], 'completed' => ['verified']];

    public static function draftPermission(string $agent): string
    {
        return ['seo' => 'seo.draft', 'growth' => 'growth.draft'][$agent] ?? 'owner';
    }

    public static function approvePermission(string $agent): string
    {
        return ['seo' => 'seo.approve', 'growth' => 'growth.approve'][$agent] ?? 'owner';
    }

    public static function viewPermission(string $agent): string
    {
        return ['seo' => 'seo.view', 'growth' => 'growth.view'][$agent] ?? 'owner';
    }

    public static function create(?User $by, array $v): string
    {
        $agent = $v['agent'];
        if ($by) {
            abort_unless(Access::can($by, self::draftPermission($agent)), 403, 'Your role cannot create tasks for this area.');
        }
        if (! empty($v['fingerprint']) && DB::table('ai_tasks')->where('fingerprint', $v['fingerprint'])->whereNotIn('status', ['verified', 'rejected', 'cancelled', 'failed'])->exists()) {
            return (string) DB::table('ai_tasks')->where('fingerprint', $v['fingerprint'])->whereNotIn('status', ['verified', 'rejected', 'cancelled', 'failed'])->value('id');
        }
        $id = (string) Str::uuid();
        $status = $by ? (($v['submit'] ?? false) ? 'submitted' : 'draft') : 'submitted';
        DB::table('ai_tasks')->insert(['id' => $id, 'agent' => $agent, 'title' => mb_substr(trim($v['title']), 0, 200), 'detail' => mb_substr(trim((string) ($v['detail'] ?? '')), 0, 8000),
            'status' => $status, 'priority' => $v['priority'] ?? 'normal', 'fingerprint' => $v['fingerprint'] ?? null, 'created_by' => $by?->id, 'assigned_to' => $v['assignedTo'] ?? null,
            'due_at' => $v['dueAt'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        self::event($id, '', $status, $by?->id, $by ? 'Created' : 'Created by the '.self::AGENTS[$agent].' agent');

        return $id;
    }

    /** A move by a person; approve / reject / verify go through decide(). */
    public static function move(User $u, string $id, string $to, string $note = ''): object
    {
        $t = self::find($id);
        abort_unless(Access::can($u, self::draftPermission($t->agent)) || Access::can($u, self::approvePermission($t->agent)), 403);
        if (in_array($to, self::DECISIONS[$t->status] ?? [], true)) {
            return self::decide($u, $id, $to, $note);
        }
        abort_unless(in_array($to, self::MOVES[$t->status] ?? [], true), 422, 'A task that is '.self::STATUSES[$t->status].' cannot move to '.(self::STATUSES[$to] ?? $to).'.');
        abort_if(in_array($to, ['blocked', 'failed', 'cancelled'], true) && mb_strlen(trim($note)) < 5, 422, 'Say why in a few words.');

        return self::set($t, $to, $u->id, $note);
    }

    public static function decide(User $u, string $id, string $to, string $note = ''): object
    {
        $t = self::find($id);
        abort_unless(in_array($to, self::DECISIONS[$t->status] ?? [], true), 422, 'This task is '.self::STATUSES[$t->status].'; it cannot be '.(self::STATUSES[$to] ?? $to).' now.');
        abort_unless(Access::can($u, self::approvePermission($t->agent)), 403, 'Your role cannot approve or verify '.self::AGENTS[$t->agent].' tasks.');
        abort_if($t->created_by && (int) $t->created_by === $u->id && ! Access::isOwner($u), 403, 'You cannot approve or verify a task you created yourself.');
        if ($to === 'rejected') {
            abort_if(mb_strlen(trim($note)) < 5, 422, 'Say why it is rejected.');
        }
        if ($to === 'verified') {
            abort_unless(DB::table('ai_task_evidence')->where('task_id', $id)->exists(), 422, 'Add evidence first (a link, a number or a note showing the result).');
            abort_if($t->assigned_to && (int) $t->assigned_to === $u->id && ! Access::isOwner($u), 403, 'The person who did the work cannot verify it.');
            DB::table('ai_tasks')->where('id', $id)->update(['reviewer_id' => $u->id, 'verified_at' => now()]);
        }

        return self::set($t, $to, $u->id, $note);
    }

    public static function evidence(User $u, string $id, string $kind, string $value): void
    {
        $t = self::find($id);
        abort_unless(Access::can($u, self::draftPermission($t->agent)) || Access::can($u, self::approvePermission($t->agent)), 403);
        abort_unless(in_array($t->status, ['in_progress', 'completed', 'overdue'], true), 422, 'Evidence is added while the task is being done or once it is completed.');
        if ($kind === 'link') {
            abort_unless(preg_match('#^https?://[^\s]{3,}$#', $value), 422, 'Paste a full link starting with https://');
        }
        DB::table('ai_task_evidence')->insert(['task_id' => $id, 'kind' => $kind, 'value' => mb_substr(trim($value), 0, 1000), 'added_by' => $u->id, 'created_at' => now()]);
        self::event($id, $t->status, $t->status, $u->id, 'Evidence added: '.mb_strimwidth(trim($value), 0, 200, '…'));
    }

    public static function list(User $u, array $f = []): array
    {
        $agents = array_values(array_filter(array_keys(self::AGENTS), fn ($a) => Access::can($u, self::viewPermission($a))));
        $q = DB::table('ai_tasks as t')->leftJoin('users as a', 'a.id', '=', 't.assigned_to')->whereIn('t.agent', $agents ?: ['-']);
        if (! empty($f['agent'])) {
            $q->where('t.agent', $f['agent']);
        }
        if (! empty($f['status'])) {
            $q->where('t.status', $f['status']);
        } elseif (empty($f['all'])) {
            $q->whereNotIn('t.status', ['verified', 'rejected', 'cancelled']);
        }

        return $q->orderByRaw("case t.priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderByDesc('t.updated_at')->limit(200)
            ->get(['t.id', 't.agent', 't.title', 't.status', 't.priority', 't.due_at', 't.created_by', 't.created_at', 't.updated_at', 'a.name as assignee'])
            ->map(fn ($x) => (array) $x + ['statusLabel' => self::STATUSES[$x->status], 'agentLabel' => self::AGENTS[$x->agent], 'byAgent' => $x->created_by === null])->all();
    }

    public static function show(User $u, string $id): array
    {
        $t = self::find($id);
        abort_unless(Access::can($u, self::viewPermission($t->agent)), 403);
        $canWork = Access::can($u, self::draftPermission($t->agent)) || Access::can($u, self::approvePermission($t->agent));
        $canDecide = Access::can($u, self::approvePermission($t->agent)) && ! ($t->created_by && (int) $t->created_by === $u->id && ! Access::isOwner($u));

        return ['task' => (array) $t + ['statusLabel' => self::STATUSES[$t->status], 'agentLabel' => self::AGENTS[$t->agent]],
            'events' => DB::table('ai_task_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->where('e.task_id', $id)->orderBy('e.id')->get(['e.from_status', 'e.to_status', 'e.note', 'e.created_at', 'u.name as actor'])->map(fn ($e) => (array) $e)->all(),
            'evidence' => DB::table('ai_task_evidence as v')->leftJoin('users as u', 'u.id', '=', 'v.added_by')->where('v.task_id', $id)->orderBy('v.id')->get(['v.kind', 'v.value', 'v.created_at', 'u.name as by'])->map(fn ($e) => (array) $e)->all(),
            'moves' => $canWork ? (self::MOVES[$t->status] ?? []) : [],
            'decisions' => $canDecide ? (self::DECISIONS[$t->status] ?? []) : [],
            'canAddEvidence' => $canWork && in_array($t->status, ['in_progress', 'completed', 'overdue'], true)];
    }

    /** Cron: approved or in-progress tasks past their due date become Overdue. */
    public static function overdue(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('ai_tasks')) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('ai_tasks')->whereIn('status', ['approved', 'in_progress'])->whereNotNull('due_at')->where('due_at', '<', now())->get() as $t) {
            self::set($t, 'overdue', null, 'Past its due date');
            $n++;
        }

        return $n;
    }

    /** The Growth agent's daily findings (high and medium) become tasks for a person to approve. */
    public static function fromGrowthFindings(array $findings): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('ai_tasks')) {
            return 0;
        }
        $n = 0;
        foreach ($findings as $f) {
            if (! in_array($f['sev'] ?? '', ['high', 'medium'], true)) {
                continue;
            }
            $before = DB::table('ai_tasks')->count();
            self::create(null, ['agent' => 'growth', 'title' => mb_substr((string) $f['finding'], 0, 200), 'priority' => $f['sev'] === 'high' ? 'high' : 'normal', 'fingerprint' => 'growth:'.sha1((string) $f['finding']),
                'detail' => "Why: ".($f['cause'] ?? '')."\nEvidence: ".implode('; ', (array) ($f['evidence'] ?? []))."\nProposed action: ".($f['action'] ?? '')."\nExpected impact: ".($f['impact'] ?? '')."\nHow we measure it: ".($f['measure'] ?? '')]);
            $n += DB::table('ai_tasks')->count() - $before;
        }

        return $n;
    }

    /** The SEO agent's site findings (duplicate titles, orphan pages, changes waiting) become tasks. */
    public static function fromSeoFindings(array $site, int $waiting): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('ai_tasks')) {
            return 0;
        }
        $n = 0;
        $make = function (string $key, string $title, string $detail, string $priority = 'normal') use (&$n) {
            $before = DB::table('ai_tasks')->count();
            self::create(null, ['agent' => 'seo', 'title' => $title, 'detail' => $detail, 'priority' => $priority, 'fingerprint' => 'seo:'.$key]);
            $n += DB::table('ai_tasks')->count() - $before;
        };
        if ($c = count($site['duplicateTitles'] ?? [])) {
            $make('dup-titles', 'Give '.$c.' page'.($c === 1 ? '' : 's').' a title of their own', "Pages share the same title, so Google may show the wrong one.\nEvidence: ".implode('; ', array_map(fn ($d) => $d['title'], array_slice($site['duplicateTitles'], 0, 5))));
        }
        if (is_array($site['orphans'] ?? null) && ($c = count($site['orphans']))) {
            $make('orphans', 'Link to '.$c.' page'.($c === 1 ? '' : 's').' that nothing links to', "Pages with no internal links are found and ranked slowly.\nEvidence: ".implode(', ', array_slice($site['orphans'], 0, 8)));
        }
        if ($waiting > 0) {
            $make('waiting:'.now()->toDateString(), 'Review '.$waiting.' SEO change'.($waiting === 1 ? '' : 's').' waiting for approval', 'Title, description and internal-link changes proposed by the SEO agent are in the Approval Centre.', 'high');
        }

        return $n;
    }

    // ---- internals ----

    private static function find(string $id): object
    {
        $t = DB::table('ai_tasks')->where('id', $id)->first();
        abort_unless($t, 404);

        return $t;
    }

    private static function set(object $t, string $to, ?int $actor, string $note): object
    {
        $ok = DB::table('ai_tasks')->where('id', $t->id)->where('status', $t->status)->update(['status' => $to, 'updated_at' => now()]);
        abort_unless($ok, 409, 'Someone else changed this task. Reload and try again.');
        self::event($t->id, $t->status, $to, $actor, $note);

        return DB::table('ai_tasks')->where('id', $t->id)->first();
    }

    private static function event(string $id, string $from, string $to, ?int $actor, string $note): void
    {
        DB::table('ai_task_events')->insert(['task_id' => $id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor, 'note' => mb_substr($note, 0, 1000), 'created_at' => now()]);
    }
}
