<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\Campaigns;
use App\Services\EmailRenderer;
use App\Services\MailAutomation;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner side of "Email campaigns". Reached through /api/admin/campaigns… */
class CampaignAdminController extends Controller
{
    public const ROUTES = '#^campaigns(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        $get = $r->isMethod('GET');
        if ($path === 'campaigns' && $get) {
            return $ok($this->snapshot());
        }
        if ($path === 'campaigns/count' && $get) {
            $aud = (string) $r->query('audience');
            abort_unless(isset(Campaigns::AUDIENCES[$aud]), 422, 'Choose who should get this campaign.');

            return $ok(['audience' => $aud, 'count' => Campaigns::count($aud)]);
        }
        if ($path === 'campaigns/contacts' && $get) {
            $q = DB::table('email_contacts')->orderByDesc('id');
            if (($s = trim((string) $r->query('q'))) !== '') {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($s)).'%';
                $q->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like)->orWhere('source', 'like', $like));
            }
            $page = max(1, (int) $r->query('page', 1));
            $total = (clone $q)->count();

            return $ok(['contacts' => $q->forPage($page, 50)->get(['id', 'email', 'name', 'source', 'confirmed_at', 'unsubscribed_at', 'created_at']), 'total' => $total, 'page' => $page, 'per' => 50]);
        }
        abort_unless($r->isMethod('POST'), 405);
        $text = ['subject' => 'required|string|max:180', 'body' => 'required|string|max:20000'];
        if ($path === 'campaigns/preview') {
            $v = $r->validate($text);
            $u = $r->user();
            $subject = Campaigns::fill($v['subject'], $u->name, $u->email);

            return $ok(['subject' => $subject] + EmailRenderer::render($subject, Campaigns::fill($v['body'], $u->name, $u->email), true, $u->id));
        }
        if ($path === 'campaigns/test') {
            $v = $r->validate($text);
            Campaigns::test($r->user(), $v['subject'], $v['body']);
            Settings::audit($id, 'campaign_test_sent');

            return $ok(['to' => $r->user()->email, 'emailOn' => (bool) (Settings::get('integrations')['email'] ?? false)]);
        }
        if ($path === 'campaigns/save') {
            $v = $r->validate(['id' => ['nullable', 'regex:/^[a-f0-9-]{36}$/'], 'name' => 'required|string|max:120', 'audience' => 'required|in:'.implode(',', array_keys(Campaigns::AUDIENCES))] + $text);
            $now = now();
            if (! empty($v['id'])) {
                $c = $this->find($v['id']);
                abort_if($c->started_at || in_array($c->status, ['done', 'cancelled'], true), 422, 'This campaign has already started, so its text is fixed. Use Copy to make a new one.');
                DB::table('email_campaigns')->where('id', $c->id)->update(['name' => $v['name'], 'subject' => $v['subject'], 'body' => $v['body'], 'audience' => $v['audience'], 'updated_at' => $now]);
                $cid = $c->id;
            } else {
                $cid = (string) Str::uuid();
                DB::table('email_campaigns')->insert(['id' => $cid, 'name' => $v['name'], 'subject' => $v['subject'], 'body' => $v['body'], 'audience' => $v['audience'], 'status' => 'draft', 'created_by' => $id, 'created_at' => $now, 'updated_at' => $now]);
            }
            Settings::audit($id, 'campaign_saved', $cid);

            return $ok($this->snapshot() + ['id' => $cid]);
        }
        if ($path === 'campaigns/send') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['id' => ['required', 'regex:/^[a-f0-9-]{36}$/'], 'when' => 'required|in:now,schedule', 'at' => ['required_if:when,schedule', 'nullable', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/']]);
            $c = $this->find($v['id']);
            abort_unless(in_array($c->status, ['draft', 'scheduled', 'paused'], true) && ! $c->started_at, 422, 'This campaign has already been sent or cancelled.');
            abort_unless(Campaigns::count($c->audience) > 0, 422, 'Nobody in this audience can be emailed yet.');
            if ($v['when'] === 'schedule') {
                $at = Campaigns::toUtc($v['at']);
                abort_if($at->lte(now()), 422, 'Pick a time in the future (India time).');
                DB::table('email_campaigns')->where('id', $c->id)->update(['status' => 'scheduled', 'scheduled_at' => $at, 'updated_at' => now()]);
                Settings::audit($id, 'campaign_scheduled', $c->id);
            } else {
                Campaigns::start($c);
                Settings::audit($id, 'campaign_started', $c->id);
            }

            return $ok($this->snapshot());
        }
        if ($path === 'campaigns/settings') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['batchSize' => 'required|integer|min:1|max:100', 'dailyCap' => 'required|integer|min:1|max:10000', 'mailboxCap' => 'sometimes|integer|min:'.(MailAutomation::RESERVE + 50).'|max:100000']);
            $box = (int) ($v['mailboxCap'] ?? Campaigns::settings()['mailboxCap']);
            Settings::put('campaignSettings', ['batchSize' => (int) $v['batchSize'], 'dailyCap' => (int) $v['dailyCap'], 'mailboxCap' => $box]);
            Settings::audit($id, 'campaign_settings_saved', $v['batchSize'].'/'.$v['dailyCap']);

            return $ok($this->snapshot());
        }
        if ($path === 'campaigns/contacts/import') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['csv' => 'required|string|max:2000000', 'source' => 'nullable|string|max:120', 'confirm' => 'accepted'], ['confirm.accepted' => 'Tick the box to confirm these people agreed to hear from CVTara.']);
            abort_if(count(preg_split('/\R/', trim($v['csv']))) > 20000, 422, 'Import up to 20,000 rows at a time.');
            $res = Campaigns::import($id, $v['csv'], trim((string) ($v['source'] ?? '')));
            Settings::audit($id, 'contacts_imported', $res['added'].' added');

            return $ok(['result' => $res] + $this->snapshot());
        }
        if ($path === 'campaigns/contacts/delete') {
            $v = $r->validate(['id' => 'required|integer']);
            DB::table('email_contacts')->where('id', $v['id'])->delete();
            Settings::audit($id, 'contact_deleted', (string) $v['id']);

            return $ok($this->snapshot());
        }
        if ($path === 'campaigns/suppress') {
            $v = $r->validate(['emails' => 'required|string|max:200000']);
            $list = array_keys(Campaigns::parse($v['emails'])['rows']);
            foreach (array_chunk($list, 500) as $chunk) {
                DB::table('email_suppressions')->insertOrIgnore(array_map(fn ($e) => ['email' => $e, 'reason' => 'owner', 'created_at' => now(), 'updated_at' => now()], $chunk));
            }
            Settings::audit($id, 'emails_suppressed', count($list).' addresses');

            return $ok(['added' => count($list)] + $this->snapshot());
        }
        if (preg_match('#^campaigns/(pause|resume|cancel|delete)$#', $path, $m)) {
            $c = $this->find((string) $r->input('id'));
            if ($m[1] === 'pause') {
                abort_unless(in_array($c->status, ['scheduled', 'sending'], true), 422, 'Only a scheduled or sending campaign can be paused.');
                Campaigns::pause($c);
            } elseif ($m[1] === 'resume') {
                AccountSecurity::requireRecent($r);
                abort_unless($c->status === 'paused', 422, 'This campaign is not paused.');
                Campaigns::resume($c);
            } elseif ($m[1] === 'cancel') {
                abort_unless(in_array($c->status, ['scheduled', 'sending', 'paused'], true), 422, 'Only a scheduled, sending or paused campaign can be cancelled.');
                Campaigns::cancel($c);
            } else {
                abort_if($c->started_at && ! in_array($c->status, ['done', 'cancelled'], true), 422, 'Pause or cancel the campaign first.');
                DB::table('campaign_recipients')->where('campaign_id', $c->id)->delete();
                DB::table('email_campaigns')->where('id', $c->id)->delete();
            }
            Settings::audit($id, 'campaign_'.$m[1], $c->id);

            return $ok($this->snapshot());
        }
        abort(404);
    }

    private function find(string $cid): object
    {
        $c = preg_match('/^[a-f0-9-]{36}$/', $cid) ? DB::table('email_campaigns')->where('id', $cid)->first() : null;
        abort_unless($c, 404);

        return $c;
    }

    private function snapshot(): array
    {
        $list = DB::table('email_campaigns')->orderByDesc('created_at')->limit(100)->get()->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'subject' => $c->subject, 'body' => $c->body, 'audience' => $c->audience, 'status' => $c->status,
            'scheduledAt' => Campaigns::local($c->scheduled_at), 'startedAt' => Campaigns::local($c->started_at), 'finishedAt' => Campaigns::local($c->finished_at), 'createdAt' => Campaigns::local($c->created_at),
            'stats' => Campaigns::stats($c->id),
        ])->all();

        return [
            'campaigns' => $list,
            'audiences' => collect(Campaigns::AUDIENCES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()->all(),
            'counts' => Campaigns::counts(),
            'settings' => Campaigns::settings(),
            'defaults' => Campaigns::DEFAULTS,
            'sentToday' => Campaigns::sentToday(),
            'contacts' => ['total' => DB::table('email_contacts')->count(), 'unsubscribed' => DB::table('email_contacts')->whereNotNull('unsubscribed_at')->count()],
            'doNotMail' => DB::table('email_suppressions')->count(),
            'emailOn' => (bool) (Settings::get('integrations')['email'] ?? false),
        ];
    }
}
