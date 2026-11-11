<?php

namespace App\Services;

use App\Http\Middleware\RequestId;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log (3.37.0). Every change made in the console is written here with who, which role, what, the
 * values before and after (for settings), the IP and the request reference. There is no code path that edits or
 * deletes a row. Each row stores the hash of the row before it, so a row changed or removed directly in the database
 * breaks the chain; the daily check reports that in the Error Log.
 */
class Audit
{
    /** Never stored: secrets and personal message bodies. */
    private const SECRET = '/(secret|password|apikey|api_key|token|key$|keyid|serviceaccount|code$|destination|account(number)?$|ifsc|upi)/i';

    /** Console paths whose saved settings are kept before and after. */
    private const SETTINGS = ['settings' => 'settings', 'plans' => 'plans', 'business-controls' => 'businessControls', 'growth/settings' => 'growth', 'growth/seo/settings' => 'seoAgent', 'tara/settings' => 'taraChat', 'refunds/settings' => 'refunds', 'services/settings' => 'services', 'referrals/settings' => 'referrals', 'speed/save' => 'speed', 'campaigns/settings' => 'campaigns', 'outreach/settings' => 'outreach', 'growth/levels' => 'agentLevels', 'growth/costs' => 'growthCosts', 'interviews/settings' => 'interviews'];

    public static function record(?User $actor, string $action, string $target = '', $before = null, $after = null, string $reason = '', int $status = 200, ?Request $r = null): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }
            Cache::lock('audit-chain', 10)->block(5, function () use ($actor, $action, $target, $before, $after, $reason, $status, $r) {
                $prev = (string) (DB::table('audit_logs')->orderByDesc('id')->value('hash') ?? '');
                $row = [
                    'at' => now()->format('Y-m-d H:i:s'), 'actor_id' => $actor?->id, 'actor_role' => (string) (Access::role($actor) ?? ''),
                    'action' => mb_substr($action, 0, 120), 'target' => mb_substr($target, 0, 190),
                    'before_json' => $before === null ? null : json_encode(self::clean($before), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after_json' => $after === null ? null : json_encode(self::clean($after), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'reason' => mb_substr($reason, 0, 500), 'ip' => (string) ($r?->ip() ?? request()?->ip() ?? ''), 'request_id' => RequestId::current(), 'status' => $status, 'prev_hash' => $prev,
                ];
                $row['hash'] = self::hash($row);
                DB::table('audit_logs')->insert($row);
            });
        } catch (\Throwable $e) {
            ErrorLog::record('warning', 'server', 'Audit log entry could not be written: '.$e->getMessage(), 'Audit');
        }
    }

    private static function hash(array $row): string
    {
        return hash('sha256', implode('|', [$row['prev_hash'], $row['at'], $row['actor_id'] ?? '', $row['actor_role'], $row['action'], $row['target'], $row['before_json'] ?? '', $row['after_json'] ?? '', $row['reason'], $row['ip'], $row['request_id'], $row['status']]));
    }

    /** Masks secrets at any depth; long texts are shortened. */
    public static function clean($v, int $depth = 0)
    {
        if (is_object($v)) {
            $v = (array) $v;
        }
        if (! is_array($v)) {
            return is_string($v) ? mb_strimwidth($v, 0, 600, '…') : $v;
        }
        if ($depth > 5) {
            return '…';
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = is_string($k) && preg_match(self::SECRET, str_replace(['_', '-'], '', $k)) && $x !== '' && $x !== null && ! is_bool($x) ? '[hidden]' : self::clean($x, $depth + 1);
        }

        return $out;
    }

    /** Called by the API router around every console write. Returns the settings snapshot before the change. */
    public static function before(string $adminPath)
    {
        $key = self::SETTINGS[$adminPath] ?? null;
        if ($adminPath === 'providers') {
            return array_map(fn ($p) => array_map(fn ($x) => is_string($x) && $x !== '' ? '[set]' : $x, (array) $p), Settings::providers());
        }

        return $key ? Settings::get($key, null) : null;
    }

    public static function afterWrite(Request $r, string $adminPath, $before, int $status): void
    {
        $key = self::SETTINGS[$adminPath] ?? null;
        $after = $adminPath === 'providers'
            ? array_map(fn ($p) => array_map(fn ($x) => is_string($x) && $x !== '' ? '[set]' : $x, (array) $p), Settings::providers())
            : ($key ? Settings::get($key, null) : null);
        $input = $r->except(['_token']);
        $size = strlen((string) json_encode($input));
        if ($size > 20000) {
            $input = ['fields' => array_keys($input), 'bytes' => $size]; // big imports: only which fields were sent
        }
        self::record($r->user(), 'console:'.$adminPath, (string) ($r->input('userId') ?? $r->input('id') ?? $r->input('purchaseId') ?? ''), $before, $after ?? ($key ? null : $input), (string) ($r->input('reason') ?? $r->input('note') ?? ''), $status, $r);
    }

    /** Daily: walks the chain and reports a break once. */
    public static function verify(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return ['ok' => true, 'rows' => 0];
        }
        $prev = '';
        $n = 0;
        $bad = null;
        foreach (DB::table('audit_logs')->orderBy('id')->cursor() as $row) {
            $a = (array) $row;
            $a['at'] = substr((string) $a['at'], 0, 19);
            if ($a['prev_hash'] !== $prev || self::hash($a) !== $a['hash']) {
                $bad = (int) $a['id'];
                break;
            }
            $prev = $a['hash'];
            $n++;
        }
        if ($bad !== null) {
            ErrorLog::record('error', 'server', 'The audit log chain is broken at entry '.$bad.': a console record was changed or removed outside CVTara.', 'Audit::verify');
        }

        return ['ok' => $bad === null, 'rows' => $n, 'brokenAt' => $bad];
    }
}
