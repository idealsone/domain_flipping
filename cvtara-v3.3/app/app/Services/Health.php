<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Daily operational snapshot shown in Setup & health. Runs from the cron once a day and on demand. */
class Health
{
    public static function snapshot(): array
    {
        $check = fn (callable $f) => (function () use ($f) {
            try {
                return (bool) $f();
            } catch (Throwable) {
                return false;
            }
        })();
        $hb = Settings::get('cronHeartbeat', []);
        $providers = Settings::providers();
        $disk = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $checks = [
            'database' => $check(fn () => DB::select('select 1')),
            'storageWritable' => $check(fn () => Storage::disk('local')->put('health.tmp', 'ok') && Storage::disk('local')->delete('health.tmp')),
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'domain' => str_contains((string) config('app.url'), (string) env('CVTARA_DOMAIN', 'cvtara.com')),
            'backupFresh' => ($b = Settings::get('lastBackup', [])) && ! empty($b['at']) && strtotime($b['at']) > now()->subHours(36)->timestamp,
            'previewOff' => ! filter_var(env('CVTARA_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
            'privateFolder' => ! str_starts_with(base_path(), public_path().DIRECTORY_SEPARATOR),
            'scheduler' => isset($hb['at']) && strtotime($hb['at']) > now()->subMinutes(10)->timestamp,
            'emailConfigured' => ! empty($providers['email']['fromEmail']),
            // SPF, DKIM and DMARC only line up when the From address is on the site's own domain (or a subdomain of it).
            'emailSenderAligned' => empty($providers['email']['fromEmail']) || (fn ($d, $site) => $site !== '' && ($d === $site || str_ends_with($d, '.'.$site)))(mb_strtolower(substr(strrchr((string) $providers['email']['fromEmail'], '@') ?: '', 1)), preg_replace('/^www\./', '', mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)))),
            'emailPostalAddress' => empty($providers['email']['fromEmail']) || trim((string) ($providers['email']['postalAddress'] ?? '')) !== '',
            // Nothing due has waited more than 10 minutes (the cron sends every minute).
            'mailQueueMoving' => ! (Settings::get('integrations')['email'] ?? false) || ! DB::table('mail_outbox')->whereIn('status', ['queued', 'failed'])->where('attempts', '<', 5)->where('due_at', '<', now()->subMinutes(10))->whereNotIn('trigger', EmailRenderer::MARKETING)->exists(),
            'paymentsConfigured' => (bool) Payments::gateways($providers['payments'] ?? []),
            'debugOff' => ! config('app.debug'),
        ];
        $metrics = [
            'users' => DB::table('users')->count(),
            'paidActive' => DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->distinct()->count('owner_id'),
            'mailQueued' => DB::table('mail_outbox')->whereIn('status', ['queued', 'pending'])->count(),
            'mailFailed24h' => DB::table('mail_outbox')->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count(),
            'errors24h' => DB::table('error_logs')->where('last_seen', '>=', now()->subDay())->where('level', 'error')->count(),
            'diskFreePct' => $total ? (int) round($disk / $total * 100) : null,
        ];
        $problems = collect($checks)->filter(fn ($v) => ! $v)->keys()->all();
        $status = (! $checks['database'] || ! $checks['storageWritable']) ? 'down' : ($problems || $metrics['errors24h'] > 0 || $metrics['mailFailed24h'] > 0 ? 'attention' : 'healthy');

        return ['at' => now()->toIso8601String(), 'date' => now()->toDateString(), 'status' => $status, 'checks' => $checks, 'metrics' => $metrics, 'problems' => $problems];
    }

    public static function run(bool $force = false): ?array
    {
        $hist = Settings::get('healthHistory', []);
        if (! $force && ($hist[0]['date'] ?? '') === now()->toDateString()) {
            return null;
        }
        $snap = self::snapshot();
        $hist = array_slice(array_values(array_filter($hist, fn ($h) => ($h['date'] ?? '') !== $snap['date'])), 0, 59);
        array_unshift($hist, $snap);
        Settings::put('healthHistory', $hist);
        if ($snap['status'] !== 'healthy') {
            ErrorLog::record('warning', 'cron', 'Daily health check: '.($snap['problems'] ? implode(', ', $snap['problems']) : 'errors or failed mail in the last 24h'), 'health:'.$snap['date']);
        }

        return $snap;
    }
}
