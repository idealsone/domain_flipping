<?php

namespace App\Http\Controllers;

use App\Services\Access;
use App\Services\AgentTasks;
use App\Services\Approvals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Approval Centre and tasks (3.42.0). The router has already checked that the person is staff; each item is checked
 * again here against its own permission (config/approvals.php, AgentTasks).
 *   GET  approvals                 everything waiting that this person may decide, and recent decisions
 *   POST approvals/decide          {category, id, decision: approve|reject, reason}
 *   GET  tasks                     open tasks of the areas this person may see (?agent=&status=&all=1)
 *   POST tasks                     {agent, title, detail, priority, dueAt, assignedTo, submit}
 *   GET  tasks/{id}                one task with its history and evidence
 *   POST tasks/{id}/move           {to, note}
 *   POST tasks/{id}/evidence       {kind: link|metric|note, value}
 */
class ApprovalsAdminController extends Controller
{
    public const ROUTES = '#^(approvals(/decide)?|tasks(/[a-f0-9-]{36}(/(move|evidence))?)?)$#';

    public function run(Request $r, string $path)
    {
        $u = $r->user();
        $get = $r->isMethod('GET');
        $ok = fn ($a = []) => response()->json(['ok' => true, ...$a]);
        if ($path === 'approvals' && $get) {
            return $ok(['items' => Approvals::pending($u), 'history' => Approvals::history($u, 50),
                'categories' => collect(Approvals::categories())->filter(fn ($c, $k) => Approvals::canDecide($u, $k))->map(fn ($c) => $c['label'])->all()]);
        }
        if ($path === 'approvals/decide' && ! $get) {
            $v = $r->validate(['category' => 'required|string|max:20', 'id' => ['required', 'regex:/^[a-f0-9-]{36}$/'], 'decision' => 'required|in:approve,reject', 'reason' => 'nullable|string|max:1000']);

            return $ok(['status' => Approvals::decide($r, $v['category'], $v['id'], $v['decision'], (string) ($v['reason'] ?? '')), 'items' => Approvals::pending($u)]);
        }
        if ($path === 'tasks' && $get) {
            return $ok(['tasks' => AgentTasks::list($u, $r->only(['agent', 'status', 'all'])), 'statuses' => AgentTasks::STATUSES,
                'agents' => collect(AgentTasks::AGENTS)->filter(fn ($l, $a) => Access::can($u, AgentTasks::viewPermission($a)))->all(),
                'canCreate' => collect(array_keys(AgentTasks::AGENTS))->filter(fn ($a) => Access::can($u, AgentTasks::draftPermission($a)))->values()->all()]);
        }
        if ($path === 'tasks' && ! $get) {
            $v = $r->validate(['agent' => 'required|in:'.implode(',', array_keys(AgentTasks::AGENTS)), 'title' => 'required|string|min:5|max:200', 'detail' => 'nullable|string|max:8000',
                'priority' => 'nullable|in:low,normal,high,urgent', 'dueAt' => 'nullable|date_format:Y-m-d', 'assignedTo' => 'nullable|integer', 'submit' => 'nullable|boolean']);
            if (! empty($v['assignedTo'])) {
                $a = \App\Models\User::find($v['assignedTo']);
                abort_unless($a && Access::can($a, AgentTasks::draftPermission($v['agent'])), 422, 'That person cannot work on tasks in this area.');
            }
            if (! empty($v['dueAt'])) {
                $v['dueAt'] = $v['dueAt'].' 23:59:59';
            }
            $id = AgentTasks::create($u, $v);

            return $ok(['id' => $id] + AgentTasks::show($u, $id));
        }
        if (preg_match('#^tasks/([a-f0-9-]{36})(?:/(move|evidence))?$#', $path, $m)) {
            if (empty($m[2])) {
                abort_unless($get, 405);

                return $ok(AgentTasks::show($u, $m[1]));
            }
            abort_if($get, 405);
            if ($m[2] === 'move') {
                $v = $r->validate(['to' => 'required|in:'.implode(',', array_keys(AgentTasks::STATUSES)), 'note' => 'nullable|string|max:1000']);
                if (in_array($v['to'], ['approved', 'rejected', 'verified'], true)) {
                    // decisions are recorded like every other approval
                    $t = DB::table('ai_tasks')->where('id', $m[1])->first();
                    abort_unless($t, 404);
                    abort_unless(Access::can($u, AgentTasks::viewPermission($t->agent)), 403);
                    AgentTasks::decide($u, $m[1], $v['to'], (string) ($v['note'] ?? ''));
                    DB::table('approval_events')->insert(['source' => 'task_'.$t->agent, 'item_id' => $m[1], 'decision' => $v['to'] === 'rejected' ? 'reject' : 'approve', 'reason' => mb_substr((string) ($v['note'] ?? ''), 0, 1000), 'decided_by' => $u->id, 'summary' => mb_substr($t->title, 0, 300), 'created_at' => now()]);
                } else {
                    AgentTasks::move($u, $m[1], $v['to'], (string) ($v['note'] ?? ''));
                }
            } else {
                $v = $r->validate(['kind' => 'required|in:link,metric,note', 'value' => 'required|string|min:3|max:1000']);
                AgentTasks::evidence($u, $m[1], $v['kind'], $v['value']);
            }

            return $ok(AgentTasks::show($u, $m[1]));
        }
        abort(404);
    }
}
