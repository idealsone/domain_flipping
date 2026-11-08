<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Collects server, browser, mail and payment problems for the owner's Error Log. Never throws. */
class ErrorLog
{
    public static function record(string $level, string $source, string $message, ?string $location = null, ?string $url = null, ?int $userId = null, array $context = []): void
    {
        try {
            $message = mb_substr(trim($message), 0, 2000);
            if ($message === '') {
                return;
            }
            $url = $url ? mb_substr(preg_replace('/[?#].*$/', '', $url), 0, 500) : null; // never store query strings (tokens)
            $message = preg_replace('/(`sessions` where `id` = )\S+/', '$1…', $message); // never keep session ids
            // Long random tokens (cache keys, ids) would otherwise turn one fault into hundreds of rows.
            $fp = hash('sha256', $source.'|'.preg_replace(['/\b[A-Za-z0-9]{20,}\b/', '/\d+/'], ['~', '#'], $message).'|'.$location);
            $now = now();
            $context['requestId'] = \App\Http\Middleware\RequestId::current();
            $hit = DB::table('error_logs')->where('fingerprint', $fp)->first();
            if ($hit) {
                DB::table('error_logs')->where('id', $hit->id)->update(['occurrences' => $hit->occurrences + 1, 'last_seen' => $now, 'resolved_at' => null, 'context' => json_encode(array_merge((array) json_decode((string) $hit->context, true), ['lastRequestId' => $context['requestId']]))]);

                return;
            }
            DB::table('error_logs')->insert(['fingerprint' => $fp, 'level' => $level, 'source' => $source, 'message' => $message, 'location' => $location ? mb_substr($location, 0, 500) : null, 'url' => $url, 'user_id' => $userId, 'context' => $context ? json_encode($context) : null, 'occurrences' => 1, 'first_seen' => $now, 'last_seen' => $now]);
            if (DB::table('error_logs')->count() > 5000) {
                DB::table('error_logs')->orderBy('last_seen')->limit(500)->delete();
            }
        } catch (Throwable) {
            // logging must never break a request; when the database itself is unreachable the entry waits in a file
            self::park($level, $source, $message, $location, $url, $userId, $context);
        }
    }

    /** File that holds entries recorded while MySQL was unreachable, until the next cron run moves them into the log. */
    public static function pendingFile(): string
    {
        return storage_path('logs/error-log-pending.jsonl');
    }

    private static function park(string $level, string $source, string $message, ?string $location, ?string $url, ?int $userId, array $context): void
    {
        try {
            $f = self::pendingFile();
            if (is_file($f) && filesize($f) > 512 * 1024) {
                return; // a long outage: enough samples are kept already
            }
            $line = json_encode(['level' => $level, 'source' => $source, 'message' => mb_substr($message, 0, 2000), 'location' => $location, 'url' => $url ? preg_replace('/[?#].*$/', '', $url) : null, 'user_id' => $userId, 'context' => $context, 'at' => now()->toDateTimeString()]);
            if ($line) {
                @file_put_contents($f, $line."\n", FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable) {
            // nothing more can be done
        }
    }

    /** Moves entries parked during a database outage into the Error Log. Called by the every-minute cron. */
    public static function flushPending(): int
    {
        $f = self::pendingFile();
        if (! is_file($f)) {
            return 0;
        }
        $work = $f.'.'.getmypid();
        if (! @rename($f, $work)) {
            return 0;
        }
        $n = 0;
        try {
            DB::connection()->getPdo(); // still down: put the entries back for the next run
        } catch (Throwable) {
            @file_put_contents($f, (string) @file_get_contents($work), FILE_APPEND | LOCK_EX);
            @unlink($work);

            return 0;
        }
        foreach (file($work, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $e = json_decode($line, true);
            if (! is_array($e) || empty($e['message'])) {
                continue;
            }
            $context = (array) ($e['context'] ?? []);
            $context['recorded_while_database_unreachable'] = $e['at'] ?? null;
            self::record((string) ($e['level'] ?? 'error'), (string) ($e['source'] ?? 'server'), (string) $e['message'], $e['location'] ?? null, $e['url'] ?? null, isset($e['user_id']) ? (int) $e['user_id'] : null, $context);
            $n++;
        }
        @unlink($work);

        return $n;
    }

    public static function exception(Throwable $e, ?int $userId = null, ?string $url = null): void
    {
        self::record('error', 'server', get_class($e).': '.$e->getMessage(), basename($e->getFile()).':'.$e->getLine(), $url, $userId);
    }
}
