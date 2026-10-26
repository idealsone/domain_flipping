<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\Outreach;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner side of Outreach: sending mailboxes, the contact list, the mail steps and the numbers. Reached through /api/admin/outreach… */
class OutreachAdminController extends Controller
{
    public const ROUTES = '#^outreach(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        $get = $r->isMethod('GET');
        if ($path === 'outreach' && $get) {
            return $ok($this->overview());
        }
        if ($path === 'outreach/contacts' && $get) {
            $q = DB::table('outreach_contacts as c')->leftJoin('outreach_senders as s', 's.id', '=', 'c.sender_id');
            if (in_array($r->query('status'), Outreach::STATUSES, true)) {
                $q->where('c.status', $r->query('status'));
            }
            if (isset(Outreach::LISTS[(string) $r->query('list')])) {
                $q->where('c.list', $r->query('list'));
            }
            if (($term = trim((string) $r->query('q'))) !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(mb_strtolower($term), 0, 80)).'%';
                $q->where(fn ($w) => $w->where('c.email', 'like', $like)->orWhere('c.name', 'like', $like)->orWhere('c.source', 'like', $like)->orWhere('c.college', 'like', $like));
            }
            $total = (clone $q)->count();
            $per = min(100, max(10, (int) $r->query('per', 20)));
            $page = max(1, (int) $r->query('page', 1));
            $rows = $q->orderByDesc('c.updated_at')->offset(($page - 1) * $per)->limit($per)->get(['c.id', 'c.email', 'c.name', 'c.list', 'c.college', 'c.designation', 'c.city', 'c.clicks', 'c.shares', 'c.source', 'c.status', 'c.step', 'c.next_at', 'c.last_sent_at', 'c.error', 'c.created_at', 's.email as sender']);

            return $ok(['total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows]);
        }
        if ($path === 'outreach/export' && $get) {
            $out = fopen('php://temp', 'w+');
            fputcsv($out, ['email', 'name', 'list', 'college', 'designation', 'city', 'source', 'status', 'mails_sent', 'clicks', 'shares', 'last_sent_at', 'mailbox'], ',', '"', '');
            DB::table('outreach_contacts as c')->leftJoin('outreach_senders as s', 's.id', '=', 'c.sender_id')->orderBy('c.created_at')->select(['c.id', 'c.email', 'c.name', 'c.list', 'c.college', 'c.designation', 'c.city', 'c.source', 'c.status', 'c.step', 'c.clicks', 'c.shares', 'c.last_sent_at', 's.email as sender'])
                ->chunk(1000, function ($rows) use ($out) {
                    foreach ($rows as $x) {
                        fputcsv($out, array_map(fn ($v) => preg_replace('/^[=+\-@\t\r]/', "'$0", (string) $v), [$x->email, $x->name, $x->list, $x->college, $x->designation, $x->city, $x->source, $x->status, $x->step, $x->clicks, $x->shares, $x->last_sent_at, $x->sender]), ',', '"', '');
                    }
                });
            rewind($out);
            Settings::audit($id, 'outreach_exported');

            return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="outreach-contacts-'.date('Y-m-d').'.csv"', 'Cache-Control' => 'private, no-store']);
        }
        abort_if($get, 405);

        if ($path === 'outreach/settings') {
            $v = $r->validate([
                'enabled' => 'required|boolean', 'windowStart' => 'required|integer|min:0|max:23', 'windowEnd' => 'required|integer|min:1|max:24', 'footer' => 'nullable|string|max:400',
                'warmup' => 'required|array', 'warmup.share' => 'required|integer|min:0|max:60', 'warmup.seeds' => 'present|array|max:'.Outreach::MAX_SEEDS, 'warmup.seeds.*' => 'email|max:190',
                'lists' => 'required|array:'.implode(',', array_keys(Outreach::LISTS)), 'lists.*.design' => 'required|in:card,plain',
                'lists.*.steps' => 'required|array|min:1|max:4', 'lists.*.steps.*.subject' => 'required|string|max:160', 'lists.*.steps.*.body' => 'required|string|max:5000', 'lists.*.steps.*.delayDays' => 'required|integer|min:0|max:60',
            ], ['lists.*.steps.*.subject.required' => 'Every mail needs a subject.', 'lists.*.steps.*.body.required' => 'Every mail needs a message.', 'warmup.seeds.*.email' => 'Each warm-up address must be a valid email address.', 'warmup.seeds.max' => 'Use up to '.Outreach::MAX_SEEDS.' warm-up addresses.']);
            abort_unless($v['windowEnd'] > $v['windowStart'], 422, 'The send window must end after it starts.');
            abort_unless(count($v['lists']) === count(Outreach::LISTS), 422, 'Both lists need their mails.');
            $lists = [];
            foreach ($v['lists'] as $k => $l) {
                foreach ($l['steps'] as $i => $st) {
                    abort_if($i > 0 && $st['delayDays'] < 1, 422, 'Follow-ups need at least 1 day after the previous mail.');
                }
                $lists[$k] = ['design' => $l['design'], 'steps' => array_map(fn ($st, $i) => ['subject' => trim($st['subject']), 'body' => trim(str_replace("\r", '', $st['body'])), 'delayDays' => $i === 0 ? 0 : (int) $st['delayDays']], $l['steps'], array_keys($l['steps']))];
            }
            if ($v['enabled'] && ! (Outreach::config()['enabled'] ?? false)) {
                AccountSecurity::requireRecent($r);
            }
            Settings::put('outreach', ['enabled' => (bool) $v['enabled'], 'windowStart' => (int) $v['windowStart'], 'windowEnd' => (int) $v['windowEnd'], 'footer' => trim((string) ($v['footer'] ?? '')),
                'warmup' => ['share' => (int) $v['warmup']['share'], 'seeds' => array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $v['warmup']['seeds'])))], 'lists' => $lists]);
            Settings::audit($id, $v['enabled'] ? 'outreach_saved_on' : 'outreach_saved_off');

            return $ok($this->overview());
        }

        if ($path === 'outreach/senders') {
            $v = $r->validate(['action' => 'required|in:add,update,delete,pause,resume,test,restart', 'id' => 'nullable|uuid']);
            $s = $v['action'] === 'add' ? null : DB::table('outreach_senders')->where('id', $v['id'] ?? '')->first();
            abort_if($v['action'] !== 'add' && ! $s, 404);
            if (in_array($v['action'], ['add', 'update'], true)) {
                AccountSecurity::requireRecent($r);
                $f = $r->validate([
                    'email' => 'required|email|max:190', 'fromName' => 'nullable|string|max:80', 'host' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9.-]+$/i'], 'port' => 'required|integer|in:25,465,587,2525',
                    'user' => 'required|string|max:190', 'password' => ($s ? 'nullable' : 'required').'|string|max:300',
                    'warmupStart' => 'required|integer|min:1|max:200', 'warmupStep' => 'required|integer|min:0|max:100', 'dailyCap' => 'required|integer|min:1|max:1000',
                ]);
                $email = mb_strtolower($f['email']);
                abort_if(DB::table('outreach_senders')->where('email', $email)->when($s, fn ($q) => $q->where('id', '!=', $s->id))->exists(), 422, 'That mailbox is already added.');
                abort_if($f['warmupStart'] > $f['dailyCap'], 422, 'The first-day amount cannot be higher than the daily cap.');
                $row = ['email' => $email, 'from_name' => trim((string) ($f['fromName'] ?? '')), 'smtp_host' => mb_strtolower($f['host']), 'smtp_port' => (int) $f['port'], 'smtp_user' => $f['user'], 'warmup_start' => (int) $f['warmupStart'], 'warmup_step' => (int) $f['warmupStep'], 'daily_cap' => (int) $f['dailyCap'], 'updated_at' => now()];
                if (! empty($f['password'])) {
                    $row['smtp_password'] = Crypt::encryptString($f['password']);
                }
                if ($s) {
                    DB::table('outreach_senders')->where('id', $s->id)->update($row);
                } else {
                    DB::table('outreach_senders')->insert($row + ['id' => (string) Str::uuid(), 'enabled' => true, 'created_at' => now()]);
                }
                Settings::audit($id, $s ? 'outreach_mailbox_updated' : 'outreach_mailbox_added', $email);

                return $ok($this->overview());
            }
            if ($v['action'] === 'delete') {
                DB::table('outreach_contacts')->where('sender_id', $s->id)->whereIn('status', ['queued', 'active'])->update(['sender_id' => null, 'updated_at' => now()]);
                DB::table('outreach_senders')->where('id', $s->id)->delete();
                Settings::audit($id, 'outreach_mailbox_deleted', $s->email);

                return $ok($this->overview());
            }
            if ($v['action'] === 'pause' || $v['action'] === 'resume') {
                DB::table('outreach_senders')->where('id', $s->id)->update(['enabled' => $v['action'] === 'resume', 'paused_reason' => $v['action'] === 'pause' ? 'Paused by you' : '', 'failures' => 0, 'updated_at' => now()]);

                return $ok($this->overview());
            }
            if ($v['action'] === 'restart') {
                // Start the warm-up again from day 1, for example after a long break.
                DB::table('outreach_senders')->where('id', $s->id)->update(['started_on' => null, 'updated_at' => now()]);

                return $ok($this->overview());
            }
            $t = $r->validate(['to' => 'nullable|email|max:190', 'list' => 'nullable|in:'.implode(',', array_keys(Outreach::LISTS))]);
            $to = $t['to'] ?? $r->user()->email;
            $list = $t['list'] ?? 'students';
            $c = Outreach::config();
            $mail = Outreach::render($c['lists'][$list]['steps'][0], (object) ['id' => (string) Str::uuid(), 'email' => $to, 'name' => $r->user()->name, 'list' => $list, 'college' => 'ABC College of Engineering'], $s, $c);
            try {
                Outreach::deliver($s, $to, ['subject' => '[Test] '.$mail['subject'], 'unsubscribe' => ''] + $mail);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'message' => 'The mailbox could not send: '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 240)], 422);
            }
            Settings::audit($id, 'outreach_test_sent', $s->email);

            return $ok(['message' => 'Test mail sent to '.$to.'. Check that it arrived in the inbox, not spam.']);
        }

        if ($path === 'outreach/import') {
            $v = $r->validate(['text' => 'required|string|max:3000000', 'source' => 'nullable|string|max:120', 'list' => 'nullable|in:'.implode(',', array_keys(Outreach::LISTS)), 'consent' => 'accepted', 'checkDomains' => 'nullable|boolean'], ['consent.accepted' => 'Confirm that everyone on this list agreed to hear from you.']);
            @set_time_limit(120);
            $res = Outreach::import($v['text'], trim((string) ($v['source'] ?? '')) ?: 'Import '.now()->format('d M Y'), $r->boolean('checkDomains', true), $v['list'] ?? 'students');
            Settings::audit($id, 'outreach_imported', $res['added'].' added of '.$res['received']);

            return $ok(['result' => $res]);
        }
        if ($path === 'outreach/suppress') {
            $v = $r->validate(['text' => 'required|string|max:1000000', 'status' => 'required|in:unsubscribed,bounced']);
            $n = Outreach::suppress(preg_split('/[\s,;]+/', $v['text']), $v['status']);
            Settings::audit($id, 'outreach_suppressed', $v['status'].': '.$n);

            return $ok(['updated' => $n]);
        }
        if ($path === 'outreach/stop-queued') {
            $n = DB::table('outreach_contacts')->where('status', 'queued')->update(['status' => 'removed', 'updated_at' => now()]);
            Settings::audit($id, 'outreach_queue_cleared', (string) $n);

            return $ok(['updated' => $n]);
        }
        if ($path === 'outreach/preview') {
            $v = $r->validate(['subject' => 'required|string|max:160', 'body' => 'required|string|max:5000', 'name' => 'nullable|string|max:120', 'list' => 'nullable|in:'.implode(',', array_keys(Outreach::LISTS)), 'design' => 'nullable|in:card,plain']);
            $sender = DB::table('outreach_senders')->orderBy('created_at')->first() ?: (object) ['email' => 'you@example.in', 'from_name' => 'Your name'];
            $list = $v['list'] ?? 'students';
            $sample = $list === 'colleges' ? ['name' => 'Dr. Ramesh Kumar', 'college' => 'ABC College of Engineering', 'designation' => 'Training and Placement Officer', 'city' => 'Mysuru'] : ['name' => 'Asha Rao', 'college' => 'ABC College of Engineering'];
            $mail = Outreach::render($v, (object) (['id' => (string) Str::uuid(), 'email' => 'asha@example.com', 'list' => $list] + $sample), $sender, Outreach::config(), $v['design'] ?? null);

            return $ok(['subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html']]);
        }
        abort(404);
    }

    private function overview(): array
    {
        $c = Outreach::config();
        $senders = DB::table('outreach_senders')->orderBy('created_at')->get()->map(function ($s) {
            $sent = Outreach::sentLast24h($s->id);
            $warm = Outreach::sentLast24h($s->id, 'warmup');
            $limit = Outreach::dailyLimit($s);
            $all = DB::table('outreach_sends')->where('sender_id', $s->id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

            return ['id' => $s->id, 'email' => $s->email, 'fromName' => $s->from_name, 'host' => $s->smtp_host, 'port' => (int) $s->smtp_port, 'user' => $s->smtp_user, 'warmupStart' => (int) $s->warmup_start, 'warmupStep' => (int) $s->warmup_step, 'dailyCap' => (int) $s->daily_cap,
                'startedOn' => $s->started_on, 'enabled' => (bool) $s->enabled, 'pausedReason' => $s->paused_reason, 'todayLimit' => $limit, 'sent24h' => $sent, 'warm24h' => $warm, 'lastSentAt' => $s->last_sent_at,
                'totals' => ['sent' => (int) ($all['sent'] ?? 0), 'bounced' => (int) ($all['bounced'] ?? 0), 'failed' => (int) ($all['failed'] ?? 0)],
                'daysToCap' => $s->warmup_step > 0 ? max(0, (int) ceil(($s->daily_cap - $limit) / $s->warmup_step)) : 0];
        });
        $counts = DB::table('outreach_contacts')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $daily = DB::table('outreach_sends')->where('created_at', '>=', now()->subDays(14))->selectRaw('DATE(created_at) as day, status, count(*) as c')->groupByRaw('DATE(created_at), status')->orderBy('day')->get();
        $recent = DB::table('outreach_sends as l')->leftJoin('outreach_contacts as c', 'c.id', '=', 'l.contact_id')->leftJoin('outreach_senders as s', 's.id', '=', 'l.sender_id')->orderByDesc('l.created_at')->limit(25)->get(['l.created_at', 'l.kind', 'l.status', 'l.step', 'l.subject', 'l.error', DB::raw('COALESCE(c.email, l.to_email) as email'), 'c.list', 's.email as sender']);
        $warm30 = DB::table('outreach_sends')->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['sent', 'bounced'])->selectRaw('kind, count(*) c')->groupBy('kind')->pluck('c', 'kind');
        // Each list on its own, so the owner can see which one converts.
        $byList = [];
        foreach (array_keys(Outreach::LISTS) as $k) {
            $q = fn () => DB::table('outreach_contacts')->where('list', $k);
            $byList[$k] = ['total' => $q()->count(), 'waiting' => $q()->where('status', 'queued')->count(), 'reached' => $q()->where('step', '>', 0)->count(), 'clicked' => $q()->whereNotNull('clicked_at')->count(),
                'shares' => (int) $q()->sum('shares'), 'registered' => $q()->where('status', 'registered')->where('step', '>', 0)->count()];
        }
        $capacity = $senders->where('enabled', true)->sum('todayLimit');
        $pending = (int) ($counts['queued'] ?? 0);
        $reached = DB::table('outreach_contacts')->where('step', '>', 0)->count();
        $funnel = ['reached' => $reached, 'clicked' => DB::table('outreach_contacts')->whereNotNull('clicked_at')->count(), 'registered' => DB::table('outreach_contacts')->where('status', 'registered')->where('step', '>', 0)->count(),
            'paid' => DB::table('outreach_contacts as c')->join('purchases as p', 'p.owner_id', '=', 'c.user_id')->where('c.status', 'registered')->where('c.step', '>', 0)->whereIn('p.status', ['paid', 'partially_refunded'])->where('p.amount_paise', '>', 0)->whereNotIn('p.provider', ['referral_reward', 'manual', 'demo'])->distinct()->count('c.id'),
            'revenuePaise' => (int) DB::table('outreach_contacts as c')->join('purchases as p', 'p.owner_id', '=', 'c.user_id')->where('c.status', 'registered')->where('c.step', '>', 0)->whereIn('p.status', ['paid', 'partially_refunded'])->whereNotIn('p.provider', ['referral_reward', 'manual', 'demo'])->sum(DB::raw('p.amount_paise - p.refunded_paise'))];

        return ['config' => $c, 'senders' => $senders->values(), 'counts' => $counts, 'daily' => $daily, 'recent' => $recent, 'capacityToday' => $capacity, 'pending' => $pending, 'funnel' => $funnel, 'byList' => $byList, 'lists' => Outreach::LISTS, 'defaults' => Outreach::defaults()['lists'],
            'mix30' => ['campaign' => (int) ($warm30['campaign'] ?? 0), 'warmup' => (int) ($warm30['warmup'] ?? 0)],
            'cronOk' => ($hb = Settings::get('cronHeartbeat')) && isset($hb['at']) && strtotime($hb['at']) > time() - 600, 'timezone' => 'Asia/Kolkata', 'siteUrl' => rtrim((string) config('app.url'), '/')];
    }
}
