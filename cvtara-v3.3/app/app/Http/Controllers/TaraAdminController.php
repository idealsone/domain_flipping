<?php

namespace App\Http\Controllers;

use App\Services\AdminTable;
use App\Services\Settings;
use App\Services\TaraAI;
use App\Services\TaraBusiness;
use App\Services\TaraChat;
use App\Services\TaraOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owner side of Tara. Customer mode: switch, model, extra instructions, tools, limits, conversations, failed tools.
 * Owner mode (tara/owner…): the business assistant's chat, briefing, confirmation cards and the record of its actions.
 * /api/admin/tara…, owner accounts only.
 */
class TaraAdminController extends Controller
{
    public const ROUTES = '#^tara(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $get = $r->isMethod('GET');
        $uid = $r->user()->id;
        $day = now()->tz('Asia/Kolkata')->startOfDay()->utc();
        if ($path === 'tara' && $get) {
            $sp = TaraChat::spend();
            $week = now()->subDays(7);

            return $ok(['settings' => TaraChat::settings(), 'tools' => TaraChat::TOOLS, 'aiReady' => TaraAI::enabled(), 'welcome' => TaraChat::WELCOME, 'stats' => [
                'messagesToday' => DB::table('tara_messages')->where('mode', 'customer')->where('role', 'user')->where('created_at', '>=', $day)->count(),
                'peopleToday' => DB::table('tara_messages')->where('mode', 'customer')->where('role', 'user')->where('created_at', '>=', $day)->distinct()->count('owner_id'),
                'conversations7d' => DB::table('tara_conversations')->where('mode', 'customer')->where('created_at', '>=', $week)->count(),
                'spendTodayPaise' => $sp['todayPaise'], 'spendMonthPaise' => $sp['monthPaise'],
                'failedTools7d' => DB::table('tara_tool_calls')->where('mode', 'customer')->where('status', 'failed')->where('created_at', '>=', $week)->count(),
                'tickets7d' => DB::table('support_reports')->where('area', 'tara')->where('created_at', '>=', $week)->count(),
                'thumbsUp7d' => DB::table('tara_messages')->where('mode', 'customer')->where('rating', 1)->where('created_at', '>=', $week)->count(),
                'thumbsDown7d' => DB::table('tara_messages')->where('mode', 'customer')->where('rating', -1)->where('created_at', '>=', $week)->count(),
            ]]);
        }
        if ($path === 'tara/settings' && $r->isMethod('POST')) {
            $v = $r->validate([
                'enabled' => 'required|boolean', 'model' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9.\-\[\]]*$/i'], 'instructions' => 'nullable|string|max:4000',
                'freeDaily' => 'required|integer|min:0|max:500', 'paidDaily' => 'required|integer|min:0|max:2000', 'siteDaily' => 'required|integer|min:0|max:200000',
                'dailySpendInr' => 'required|integer|min:0|max:1000000', 'monthlySpendInr' => 'required|integer|min:0|max:10000000', 'maxTokens' => 'required|integer|min:300|max:4000',
                'historyMessages' => 'required|integer|min:4|max:60', 'maxSteps' => 'required|integer|min:1|max:8', 'tools' => 'present|array', 'tools.*' => 'string|in:'.implode(',', array_keys(TaraChat::TOOLS)),
                'handoff' => 'required|string|min:10|max:400', 'suggestions' => 'required|boolean', 'nudges' => 'sometimes|boolean', 'greet' => 'sometimes|boolean', 'peerMin' => 'sometimes|integer|min:2|max:100',
            ]);
            $v['model'] = (string) ($v['model'] ?? '');
            $v['instructions'] = (string) ($v['instructions'] ?? '');
            abort_if($v['enabled'] && ! TaraAI::enabled(), 422, 'Add the Claude API key and switch AI on in Settings → Integrations → AI first.');
            abort_if($v['enabled'] && ! $v['tools'], 422, 'Leave at least one action on, or Tara cannot look anything up.');
            abort_if(preg_match('/\b(AI|artificial intelligence|Claude|Anthropic|GPT|chatbot)\b/i', $v['handoff']), 422, 'The hand-off message is shown to customers: leave out AI and provider names.');
            Settings::put('tara', $v + ['toolsKnown' => array_keys(TaraChat::TOOLS)] + array_intersect_key(TaraChat::settings(), ['nudges' => 1, 'peerMin' => 1, 'greet' => 1]));
            Settings::audit($uid, 'tara_settings_saved', $v['enabled'] ? 'on' : 'off');

            return $ok(['settings' => TaraChat::settings()]);
        }
        if ($path === 'tara/conversations' && $get) {
            $q = DB::table('tara_conversations as c')->join('users as u', 'u.id', '=', 'c.owner_id')->where('c.mode', 'customer')->where('c.status', '!=', 'deleted')->select(['c.id', 'c.title', 'c.messages', 'c.last_at', 'c.created_at', 'u.name', 'u.email']);

            return $ok(AdminTable::query($r, $q, [
                'search' => ['u.email', 'u.name', 'c.title'], 'date' => 'c.last_at', 'sorts' => ['sort' => 'c.last_at', 'messages' => 'c.messages'], 'key' => 'c.id',
                'export' => ['title' => 'Tara conversations', 'name' => 'cvtara-tara-conversations', 'map' => fn ($rows) => array_map(fn ($x) => ['Customer' => $x['name'], 'Email' => $x['email'], 'Started with' => $x['title'], 'Messages' => $x['messages'], 'Last message' => $x['last_at']], $rows)],
            ], fn ($x) => (array) $x));
        }
        if ($path === 'tara/conversation' && $get) {
            $id = (string) $r->query('id', '');
            abort_unless(preg_match('/^[a-f0-9-]{36}$/', $id), 422);
            $c = DB::table('tara_conversations as c')->join('users as u', 'u.id', '=', 'c.owner_id')->where('c.id', $id)->where('c.mode', 'customer')->first(['c.id', 'c.title', 'c.created_at', 'u.name', 'u.email']);
            abort_unless($c, 404);
            Settings::audit($uid, 'tara_conversation_viewed', $id); // reading a customer's chat is recorded

            return $ok(['conversation' => $c, 'messages' => DB::table('tara_messages')->where('conversation_id', $id)->orderBy('created_at')->limit(300)->get(['role', 'content', 'rating', 'created_at']),
                'tools' => DB::table('tara_tool_calls')->where('conversation_id', $id)->orderBy('created_at')->get(['tool', 'status', 'error', 'ms', 'created_at'])]);
        }
        if ($path === 'tara/tools' && $get) {
            $q = DB::table('tara_tool_calls as t')->leftJoin('users as u', 'u.id', '=', 't.owner_id')->where('t.mode', 'customer')->select(['t.id', 't.conversation_id', 't.tool', 't.status', 't.error', 't.ms', 't.created_at', 'u.email']);

            return $ok(AdminTable::query($r, $q, [
                'search' => ['t.tool', 't.error', 'u.email'], 'filters' => ['status' => 't.status', 'tool' => 't.tool'], 'date' => 't.created_at', 'sorts' => ['sort' => 't.created_at', 'ms' => 't.ms'], 'key' => 't.id',
                'export' => ['title' => 'Tara actions', 'name' => 'cvtara-tara-actions', 'map' => fn ($rows) => array_map(fn ($x) => ['When' => $x['created_at'], 'Customer' => $x['email'], 'Action' => TaraChat::TOOLS[$x['tool']] ?? $x['tool'], 'Result' => $x['status'], 'Reason' => $x['error'], 'Time (ms)' => $x['ms']], $rows)],
            ], fn ($x) => (array) $x));
        }
        if (str_starts_with($path, 'tara/owner')) {
            return $this->owner($r, substr($path, 10), $ok);
        }
        abort(404);
    }

    private function owner(Request $r, string $p, callable $ok)
    {
        $u = $r->user();
        $get = $r->isMethod('GET');
        if ($p === '' && $get) {
            return $ok(TaraOwner::status());
        }
        if ($p === '/briefing' && $get) {
            $period = in_array($r->query('period'), ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month'], true) ? (string) $r->query('period') : 'today';

            return $ok(['briefing' => TaraBusiness::briefing($period, $r->boolean('fresh'))]);
        }
        if ($p === '/message' && $r->isMethod('POST')) {
            $v = $r->validate(['text' => 'required|string|max:8000', 'conversationId' => ['nullable', 'regex:/^[a-f0-9-]{36}$/']]);

            return $ok(TaraOwner::send($u, $v['conversationId'] ?? null, $v['text']));
        }
        if ($p === '/conversations' && $get) {
            return $ok(['conversations' => DB::table('tara_conversations')->where('owner_id', $u->id)->where('mode', 'owner')->where('status', '!=', 'deleted')->orderByDesc('last_at')->limit(40)->get(['id', 'title', 'messages', 'last_at'])]);
        }
        if ($p === '/conversation' && $get) {
            $id = (string) $r->query('id', '');
            $c = preg_match('/^[a-f0-9-]{36}$/', $id) ? DB::table('tara_conversations')->where('id', $id)->where('owner_id', $u->id)->where('mode', 'owner')->where('status', '!=', 'deleted')->first(['id', 'title']) : null;
            abort_unless($c, 404);
            $msgs = DB::table('tara_messages')->where('conversation_id', $id)->orderBy('created_at')->limit(300)->get(['id', 'role', 'content', 'meta_json', 'created_at']);

            return $ok(['conversation' => $c, 'messages' => $msgs->map(function ($m) {
                $meta = json_decode((string) $m->meta_json, true) ?: [];

                return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'links' => $meta['links'] ?? [], 'cards' => TaraOwner::cards($meta['actions'] ?? []), 'at' => $m->created_at];
            })]);
        }
        if ($p === '/conversation/delete' && $r->isMethod('POST')) {
            $id = (string) $r->input('id', '');
            abort_unless(preg_match('/^[a-f0-9-]{36}$/', $id) && DB::table('tara_conversations')->where('id', $id)->where('owner_id', $u->id)->where('mode', 'owner')->update(['status' => 'deleted', 'updated_at' => now()]), 404);

            return $ok();
        }
        if (preg_match('#^/actions/(confirm|cancel)$#', $p, $m) && $r->isMethod('POST')) {
            $id = (string) $r->input('id', '');
            abort_unless(preg_match('/^[a-f0-9-]{36}$/', $id), 422);

            return $ok(['card' => $m[1] === 'confirm' ? TaraOwner::confirm($r, $id) : TaraOwner::cancel($u, $id)]);
        }
        if ($p === '/actions' && $get) {
            $q = DB::table('tara_admin_actions as a')->leftJoin('users as u', 'u.id', '=', 'a.decided_by')->select(['a.id', 'a.action', 'a.target', 'a.summary', 'a.status', 'a.result', 'a.before_json', 'a.after_json', 'a.created_at', 'a.decided_at', 'u.email as decided_by']);

            return $ok(AdminTable::query($r, $q, [
                'search' => ['a.target', 'a.summary', 'a.action'], 'filters' => ['status' => 'a.status', 'action' => 'a.action'], 'date' => 'a.created_at', 'sorts' => ['sort' => 'a.created_at'], 'key' => 'a.id',
                'export' => ['title' => 'Business assistant actions', 'name' => 'cvtara-assistant-actions', 'map' => fn ($rows) => array_map(fn ($x) => ['Asked' => $x['created_at'], 'Action' => TaraOwner::ACTIONS[$x['action']] ?? $x['action'], 'For' => $x['target'], 'What' => $x['summary'],
                    'Result' => $x['status'], 'Message' => $x['result'], 'Before' => $x['before_json'], 'After' => $x['after_json'], 'Confirmed by' => $x['decided_by'], 'Decided' => $x['decided_at']], $rows)],
            ], fn ($x) => (array) $x));
        }
        if ($p === '/settings' && $r->isMethod('POST')) {
            $v = $r->validate(['model' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9.\-\[\]]*$/i'], 'instructions' => 'nullable|string|max:4000', 'monthlySpendInr' => 'required|integer|min:0|max:1000000',
                'maxTokens' => 'required|integer|min:500|max:4000', 'historyMessages' => 'required|integer|min:4|max:60', 'maxSteps' => 'required|integer|min:2|max:12']);
            $v['model'] = (string) ($v['model'] ?? '');
            $v['instructions'] = (string) ($v['instructions'] ?? '');
            Settings::put('taraOwner', $v);
            Settings::audit($u->id, 'tara_owner_settings_saved');

            return $ok(['settings' => TaraOwner::settings()]);
        }
        abort(404);
    }
}
