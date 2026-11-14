<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\AdminTable;
use App\Services\SupportAssist;
use App\Services\SupportCentre;
use App\Services\SupportInbox;
use App\Services\TaraAI;
use App\Services\SupportView;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owner side of the feedback button: inbox, status, notes and screenshots. Reached through /api/admin/support… */
class SupportAdminController extends Controller
{
    public const ROUTES = '#^support(/.*)?$#';

    private const STATUSES = ['new', 'seen', 'working', 'resolved', 'closed'];

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        if ($path === 'support/summary' && $r->isMethod('GET')) {
            $days = in_array((int) $r->query('days', 30), [7, 30, 90], true) ? (int) $r->query('days', 30) : 30;
            $since = now()->subDays($days);
            $q = fn () => DB::table('support_reports');

            return $ok([
                'days' => $days,
                'byStatus' => $q()->groupBy('status')->selectRaw('status, count(*) c')->pluck('c', 'status'),
                'recent' => ['total' => $q()->where('created_at', '>=', $since)->count(), 'byKind' => $q()->where('created_at', '>=', $since)->groupBy('kind')->selectRaw('kind, count(*) c')->pluck('c', 'kind'), 'byArea' => $q()->where('created_at', '>=', $since)->groupBy('area')->selectRaw('area, count(*) c')->pluck('c', 'area'),
                    'avgRating' => ($a = $q()->where('created_at', '>=', $since)->whereNotNull('rating')->avg('rating')) ? round((float) $a, 1) : null],
                'areas' => SupportController::AREAS, 'kinds' => SupportController::KINDS,
            ]);
        }
        if ($path === 'support' && $r->isMethod('GET')) {
            $q = DB::table('support_reports as s')->leftJoin('users as u', 'u.id', '=', 's.owner_id');
            if (in_array($r->query('status'), self::STATUSES, true)) {
                $q->where('s.status', $r->query('status'));
            } elseif ($r->query('status') === 'open') {
                $q->whereIn('s.status', ['new', 'seen', 'working']);
            }
            foreach (['area' => SupportController::AREAS, 'kind' => SupportController::KINDS] as $k => $set) {
                if (isset($set[$r->query($k)])) {
                    $q->where('s.'.$k, $r->query($k));
                }
            }
            if ($r->query('needs') === 'reply') {
                $q->whereIn('s.status', ['new', 'seen', 'working'])->where(fn ($w) => $w->whereNull('s.reply_needed')->orWhere('s.reply_needed', true));
            }
            if ($r->query('channel') === 'email') {
                $q->where('s.channel', 'email');
            }
            if ($r->query('mine') === '1') {
                $q->where('s.assigned_to', $id);
            }
            if (in_array($r->query('priority'), SupportCentre::PRIORITIES, true)) {
                $q->where('s.priority', $r->query('priority'));
            }
            if (($term = trim((string) $r->query('q'))) !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($term, 0, 80)).'%';
                $q->where(fn ($w) => $w->where('s.message', 'like', $like)->orWhere('s.reference', 'like', $like)->orWhere('s.email', 'like', $like)->orWhere('s.name', 'like', $like)->orWhere('s.page', 'like', $like));
            }
            AdminTable::dates($r, $q, 's.created_at');
            AdminTable::export($r, (clone $q)->orderByDesc('s.created_at')->select(['s.created_at', 's.name', 's.email', 's.plan', 's.area', 's.kind', 's.page', 's.rating', 's.status', 's.message', 's.resolved_at']), ['export' => ['title' => 'Support reports', 'columns' => ['created_at' => 'Sent', 'name' => 'Name', 'email' => 'Email', 'plan' => 'Plan', 'area' => 'Area', 'kind' => 'Kind', 'page' => 'Page', 'rating' => 'Rating', 'status' => 'Status', 'message' => 'Message', 'resolved_at' => 'Resolved']]]);
            $total = (clone $q)->count();
            $per = min(50, max(5, (int) $r->query('per', 20)));
            $page = max(1, (int) $r->query('page', 1));
            $rows = $q->orderByRaw("case s.status when 'new' then 0 when 'seen' then 1 when 'working' then 2 else 3 end")->orderByDesc('s.created_at')->offset(($page - 1) * $per)->limit($per)
                ->get(['s.id', 's.owner_id', 's.name', 's.email', 's.area', 's.kind', 's.page', 's.message', 's.rating', 's.status', 's.plan', 's.created_at', 's.resolved_at', 's.reference', 's.priority', 's.assigned_to', 's.last_customer_at', 's.first_response_at', 's.channel', 's.triage', 's.reply_needed', 's.triage_reason', 's.draft_at']);
            $counts = DB::table('support_attachments')->whereIn('report_id', $rows->pluck('id'))->groupBy('report_id')->selectRaw('report_id, count(*) c')->pluck('c', 'report_id');

            return $ok(['total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows->map(fn ($x) => [...(array) $x, 'message' => mb_substr($x->message, 0, 220), 'images' => (int) ($counts[$x->id] ?? 0)])->all()]);
        }
        // 3.75.0 Support by email: the support mailbox, read every two minutes ---------------------------------
        if ($path === 'support/inbox' && $r->isMethod('GET')) {
            return $ok(['inbox' => SupportInbox::status(), 'assist' => TaraAI::enabled(), 'canEdit' => Access::isOwner($r->user())]);
        }
        if ($path === 'support/inbox' && $r->isMethod('POST')) {
            abort_unless(Access::isOwner($r->user()), 403, 'Only the owner can change the support mailbox.');
            $v = $r->validate(['enabled' => 'sometimes|boolean', 'autoDraft' => 'sometimes|boolean', 'host' => 'sometimes|string|max:120|regex:/^[a-z0-9.-]+$/i',
                'port' => 'sometimes|integer|between:1,65535', 'user' => 'sometimes|email|max:190', 'password' => 'nullable|string|max:200']);
            SupportInbox::save($v);
            Settings::audit($id, 'support_inbox_saved', SupportInbox::settings()['user']);

            return $ok(['inbox' => SupportInbox::status()]);
        }
        if ($path === 'support/inbox/check' && $r->isMethod('POST')) {
            $res = SupportInbox::run(true);

            return $ok(['result' => $res, 'inbox' => SupportInbox::status()]);
        }
        // 3.40.0 Support Centre ------------------------------------------------------------------------------
        if ($path === 'support/team' && $r->isMethod('GET')) {
            // People a ticket can be given to: the owner and staff who may answer tickets.
            $ids = DB::table('user_roles')->pluck('user_id')->merge(DB::table('users')->where('is_admin', true)->pluck('id'))->unique();

            return $ok(['people' => User::whereIn('id', $ids)->where('blocked', false)->orderBy('name')->get(['id', 'name', 'email'])
                ->filter(fn ($p) => Access::can($p, 'support.manage'))->map(fn ($p) => ['id' => $p->id, 'name' => $p->name ?: $p->email])->values()->all()]);
        }
        if ($path === 'support/new' && $r->isMethod('POST')) {
            $v = $r->validate(['email' => 'required|email|max:190', 'message' => 'required|string|min:5|max:5000', 'area' => 'nullable|in:'.implode(',', array_keys(SupportController::AREAS)),
                'kind' => 'nullable|in:'.implode(',', array_keys(SupportController::KINDS)), 'priority' => 'nullable|in:'.implode(',', SupportCentre::PRIORITIES)]);
            $c = User::where('email', mb_strtolower(trim($v['email'])))->first();
            abort_unless($c, 422, 'No account uses that email. Ask the person to sign up first, or answer them by email.');
            abort_if(Access::isStaff($c), 422, 'That is a console account.');
            $tid = (string) \Illuminate\Support\Str::uuid();
            DB::table('support_reports')->insert(['id' => $tid, 'owner_id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'area' => $v['area'] ?? array_key_first(SupportController::AREAS),
                'kind' => $v['kind'] ?? 'question', 'page' => '', 'message' => $v['message'], 'status' => 'working', 'admin_note' => '', 'user_agent' => '', 'viewport' => '', 'plan' => '',
                'priority' => $v['priority'] ?? 'normal', 'channel' => 'staff', 'assigned_to' => $id, 'created_at' => now(), 'updated_at' => now()]);
            SupportCentre::stamp($tid, false);
            SupportCentre::add($tid, $id, 'system', 'Ticket opened by '.$r->user()->name.' for the customer.', true);

            return $ok(['id' => $tid, 'reference' => SupportCentre::ref($tid)]);
        }
        if (preg_match('#^support/users/(\d+)$#', $path, $m) && $r->isMethod('GET')) {
            $c = User::find((int) $m[1]);
            abort_unless($c, 404);
            abort_if(Access::isStaff($c) && ! Access::isOwner($r->user()), 403);

            return $ok(['profile' => SupportCentre::profile($c)]);
        }
        if ($path === 'support/view/start' && $r->isMethod('POST')) {
            \App\Services\AccountSecurity::requireRecent($r);
            $v = $r->validate(['customerId' => 'required|integer', 'ticketId' => 'nullable|uuid', 'reason' => 'required|string|min:10|max:500']);

            return $ok(SupportView::start($r, $r->user(), (int) $v['customerId'], $v['ticketId'] ?? null, $v['reason']));
        }
        if ($path === 'support/view/end' && $r->isMethod('POST')) {
            SupportView::end($r);

            return $ok(['active' => false]);
        }
        if ($path === 'support/view/sessions' && $r->isMethod('GET')) {
            $q = DB::table('support_sessions as s')->leftJoin('users as st', 'st.id', '=', 's.staff_id')->leftJoin('users as c', 'c.id', '=', 's.customer_id')
                ->select(['s.id', 's.ticket_id', 's.reason', 's.started_at', 's.expires_at', 's.ended_at', 'st.email as staff', 'c.email as customer']);
            if (! Access::isOwner($r->user())) {
                $q->where('s.staff_id', $id); // staff see only their own; the owner sees all
            }
            $rows = $q->orderByDesc('s.started_at')->limit(50)->get();
            $ev = DB::table('support_session_events')->whereIn('session_id', $rows->pluck('id'))->groupBy('session_id')->selectRaw('session_id, count(*) n, sum(case when status >= 400 then 1 else 0 end) refused')->get()->keyBy('session_id');

            return $ok(['sessions' => $rows->map(fn ($x) => (array) $x + ['requests' => (int) ($ev[$x->id]->n ?? 0), 'refused' => (int) ($ev[$x->id]->refused ?? 0)])->all()]);
        }
        if (preg_match('#^support/view/sessions/([a-f0-9-]{36})/end$#', $path, $m) && $r->isMethod('POST')) {
            abort_unless(Access::isOwner($r->user()), 403);
            SupportView::close($m[1], $id);

            return $ok();
        }
        if (preg_match('#^support/file/([a-f0-9-]{36})$#', $path, $m) && $r->isMethod('GET')) {
            $a = DB::table('support_attachments')->where('id', $m[1])->first();
            abort_unless($a && Storage::disk('local')->exists($a->path), 404);

            return response(Storage::disk('local')->get($a->path), 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'Content-Disposition' => 'inline; filename="screenshot.webp"', 'Content-Security-Policy' => "default-src 'none'"]);
        }
        if (preg_match('#^support/([a-f0-9-]{36})$#', $path, $m)) {
            $row = DB::table('support_reports')->where('id', $m[1])->first();
            abort_unless($row, 404);
            if ($r->isMethod('GET')) {
                if ($row->status === 'new') {
                    DB::table('support_reports')->where('id', $row->id)->update(['status' => 'seen', 'updated_at' => now()]);
                    $row->status = 'seen';
                }
                $files = DB::table('support_attachments')->where('report_id', $row->id)->orderBy('created_at')->get(['id', 'width', 'height', 'bytes'])->all();
                $history = $row->owner_id ? DB::table('support_reports')->where('owner_id', $row->owner_id)->where('id', '!=', $row->id)->count() : 0;

                $errors = $row->owner_id ? DB::table('error_logs')->where('user_id', $row->owner_id)->where('last_seen', '>=', \Illuminate\Support\Carbon::parse($row->created_at)->subDay())
                    ->orderByDesc('last_seen')->limit(5)->get(['id', 'level', 'message', 'occurrences', 'last_seen'])->map(fn ($e) => ['message' => mb_strimwidth((string) $e->message, 0, 200, '…')] + (array) $e)->all() : [];

                return $ok(['report' => array_merge((array) $row, ['reference' => ($row->reference ?? '') ?: SupportCentre::ref($row->id)]), 'images' => $files, 'earlierFromSamePerson' => $history,
                    'messages' => SupportCentre::messages($row->id, true), 'errors' => $errors, 'canViewAs' => (bool) $row->owner_id && Access::can($r->user(), 'customers.view_as'),
                    'canDelete' => Access::isOwner($r->user()), 'assist' => TaraAI::enabled(), 'canReply' => (bool) $row->owner_id || filter_var((string) $row->email, FILTER_VALIDATE_EMAIL) !== false,
                    'triageKinds' => SupportAssist::KINDS]);
            }
            $v = $r->validate(['action' => 'required|in:update,delete,reply,note,draft,sort', 'replyNeeded' => 'nullable|boolean', 'status' => 'nullable|in:'.implode(',', self::STATUSES), 'adminNote' => 'nullable|string|max:3000',
                'body' => 'required_if:action,reply,note|nullable|string|min:2|max:5000', 'priority' => 'nullable|in:'.implode(',', SupportCentre::PRIORITIES), 'assignedTo' => 'nullable|integer']);
            if ($v['action'] === 'reply') {
                abort_unless($row->owner_id || filter_var((string) $row->email, FILTER_VALIDATE_EMAIL), 422, 'This message has no email address to answer.');
                $mail = SupportCentre::staffReply($row, $r->user(), $v['body']);
                Settings::audit($id, 'support_reply', $row->id);

                return $ok(['messages' => SupportCentre::messages($row->id, true), 'mail' => $mail]);
            }
            if ($v['action'] === 'draft') {
                try {
                    return $ok(['draft' => SupportAssist::draft($row->id)]);
                } catch (\Throwable $e) {
                    abort(422, $e instanceof \RuntimeException && str_contains($e->getMessage(), 'Integrations') ? $e->getMessage() : 'A suggested reply could not be written just now ('.TaraAI::failure($e).'). Try again, or write the reply yourself.');
                }
            }
            if ($v['action'] === 'sort') {
                $need = (bool) ($v['replyNeeded'] ?? true);
                DB::table('support_reports')->where('id', $row->id)->update(['reply_needed' => $need, 'triage' => $need ? 'customer' : ($row->triage === 'customer' || ! $row->triage ? 'sales' : $row->triage), 'updated_at' => now()]
                    + ($need ? ['status' => in_array($row->status, ['resolved', 'closed'], true) ? 'seen' : $row->status, 'resolved_at' => null] : ['status' => 'closed', 'resolved_at' => $row->resolved_at ?? now()]));
                SupportCentre::add($row->id, $id, 'system', $r->user()->name.($need ? ' marked this as needing a reply.' : ' marked this as not needing a reply and closed it.'), true);

                return $ok(['messages' => SupportCentre::messages($row->id, true)]);
            }
            if ($v['action'] === 'note') {
                SupportCentre::add($row->id, $id, 'staff', $v['body'], true);

                return $ok(['messages' => SupportCentre::messages($row->id, true)]);
            }
            if ($v['action'] === 'delete') {
                abort_unless(Access::isOwner($r->user()), 403, 'Only the owner can delete a ticket.');
                foreach (DB::table('support_attachments')->where('report_id', $row->id)->pluck('path') as $p) {
                    Storage::disk('local')->delete($p);
                }
                DB::table('support_attachments')->where('report_id', $row->id)->delete();
                DB::table('support_reports')->where('id', $row->id)->delete();
                Settings::audit($id, 'support_report_deleted', $row->id);

                return $ok();
            }
            $set = ['updated_at' => now()];
            if (! empty($v['status'])) {
                $set['status'] = $v['status'];
                $set['resolved_at'] = in_array($v['status'], ['resolved', 'closed'], true) ? ($row->resolved_at ?? now()) : null;
            }
            if (array_key_exists('adminNote', $v)) {
                $set['admin_note'] = $v['adminNote'];
            }
            if (! empty($v['priority'])) {
                $set['priority'] = $v['priority'];
            }
            if (array_key_exists('assignedTo', $v)) {
                $who = $v['assignedTo'] ? User::find($v['assignedTo']) : null;
                abort_if($v['assignedTo'] && (! $who || ! Access::can($who, 'support.manage')), 422, 'That person cannot answer tickets.');
                $set['assigned_to'] = $who?->id;
            }
            DB::table('support_reports')->where('id', $row->id)->update($set);
            Settings::audit($id, 'support_report_updated', $row->id);

            return $ok();
        }
        abort(404);
    }
}
