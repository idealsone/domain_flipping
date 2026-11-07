<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\AccountSecurity;
use App\Services\AdminTable;
use App\Services\Audit;
use App\Services\MailAutomation;
use App\Services\Settings;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Owner: Team & roles and the audit log (/api/admin/team…, /api/admin/audit…). Everyone in the console: authenticator app (/api/security/totp/…). */
class TeamAdminController extends Controller
{
    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $me = $r->user();
        abort_unless(Access::isOwner($me), 403); // the router already allows only the owner here; checked again on purpose
        if ($path === 'team' && $r->isMethod('GET')) {
            return $ok(Access::team());
        }
        if ($path === 'team/save' && $r->isMethod('POST')) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['email' => 'required|email|max:190', 'name' => 'nullable|string|max:120', 'role' => 'required|in:'.implode(',', array_keys(config('permissions.roles')))]);
            $email = mb_strtolower(trim($v['email']));
            $u = User::where('email', $email)->first();
            abort_if($u && $u->is_admin, 422, 'This is an owner account; it already has full access.');
            abort_if($u && $u->blocked, 422, 'This account is blocked. Unblock it first.');
            $before = $u ? DB::table('user_roles')->where('user_id', $u->id)->value('role') : null;
            $invited = false;
            DB::transaction(function () use (&$u, $email, $v, $me, &$invited) {
                if (! $u) {
                    $u = User::create(['name' => trim((string) ($v['name'] ?? '')) ?: Str::before($email, '@'), 'email' => $email, 'password' => Hash::make(Str::random(40))]);
                    $u->forceFill(['referral_code' => \App\Services\Referrals::makeCode((string) $u->name)])->save();
                    DB::table('profiles')->insertOrIgnore(['owner_id' => $u->id, 'name' => $u->name, 'created_at' => now(), 'updated_at' => now()]);
                    $invited = true;
                }
                DB::table('user_roles')->updateOrInsert(['user_id' => $u->id], ['role' => $v['role'], 'granted_by' => $me->id, 'updated_at' => now(), 'created_at' => now()]);
            });
            Access::forget($u->id);
            if ($invited) {
                // A new staff member sets their own password from a reset link; the owner never sees or sets it.
                $token = Password::createToken($u);
                MailAutomation::queue($u, 'password_reset', hash('sha256', $token), ['reset_url' => rtrim((string) config('app.url'), '/').'/reset-password/'.$token.'?email='.urlencode($u->email)], true);
            }
            // Their open sessions end, so the new role applies at once.
            DB::table('sessions')->where('user_id', $u->id)->delete();
            Audit::record($me, 'team:role_set', (string) $u->id, ['role' => $before], ['role' => $v['role'], 'email' => $u->email, 'invited' => $invited], '', 200, $r);

            return $ok(Access::team() + ['invited' => $invited]);
        }
        if ($path === 'team/remove' && $r->isMethod('POST')) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['userId' => 'required|integer']);
            $role = DB::table('user_roles')->where('user_id', $v['userId'])->value('role');
            abort_unless($role, 404);
            DB::table('user_roles')->where('user_id', $v['userId'])->delete();
            DB::table('sessions')->where('user_id', $v['userId'])->delete();
            Access::forget((int) $v['userId']);
            Audit::record($me, 'team:role_removed', (string) $v['userId'], ['role' => $role], ['role' => null], '', 200, $r);

            return $ok(Access::team());
        }
        if ($path === 'audit' && $r->isMethod('GET')) {
            $q = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->select(['a.id', 'a.at', 'a.actor_role', 'a.action', 'a.target', 'a.before_json', 'a.after_json', 'a.reason', 'a.ip', 'a.request_id', 'a.status', 'u.email as actor']);
            if (($term = trim((string) $r->query('q', ''))) !== '') {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('a.action', 'like', $like)->orWhere('a.target', 'like', $like)->orWhere('u.email', 'like', $like)->orWhere('a.request_id', 'like', $like));
            }
            $d = AdminTable::query($r, $q, ['sorts' => ['at' => 'a.at'], 'sort' => 'at', 'export' => ['title' => 'Audit log']]);

            return $ok(['rows' => $d['rows']] + $d);
        }
        if ($path === 'audit/verify' && $r->isMethod('POST')) {
            return $ok(Audit::verify());
        }
        abort(404);
    }

    /** Authenticator app for the owner and staff: setup → enable with a code → (optional) disable with a fresh security check. */
    public function totp(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $u = $r->user();
        abort_unless(Access::isStaff($u), 403);
        abort_unless($r->isMethod('POST') || $path === 'status', 405);
        if ($path === 'status') {
            return $ok(['enabled' => Totp::enabled($u)]);
        }
        if ($path === 'setup') {
            abort_if(Totp::enabled($u), 422, 'An authenticator app is already on. Switch it off first to move to a new phone.');
            $secret = Totp::newSecret();
            $r->session()->put('totp_setup', ['secret' => $secret, 'until' => now()->timestamp + 900]);

            return $ok(['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => Totp::uri($u, $secret)]);
        }
        if ($path === 'enable') {
            $v = $r->validate(['code' => 'required|digits:6']);
            $s = $r->session()->get('totp_setup');
            abort_unless($s && $s['until'] > now()->timestamp, 422, 'Start the setup again: the key expired.');
            abort_unless(Totp::enable($u, $s['secret'], $v['code']), 422, 'That code is not right. Check the phone\'s clock and try the newest code.');
            $r->session()->forget('totp_setup');
            Audit::record($u, 'security:authenticator_on', (string) $u->id, null, null, '', 200, $r);
            Settings::audit($u->id, 'authenticator_enabled');

            return $ok(['enabled' => true]);
        }
        if ($path === 'disable') {
            AccountSecurity::requireRecent($r);
            Totp::disable($u);
            Audit::record($u, 'security:authenticator_off', (string) $u->id, null, null, '', 200, $r);

            return $ok(['enabled' => false]);
        }
        abort(404);
    }
}
