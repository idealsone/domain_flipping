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
            $fp = hash('sha256', $source.'|'.preg_replace('/\d+/', '#', $message).'|'.$location);
            $now = now();
            $hit = DB::table('error_logs')->where('fingerprint', $fp)->first();
            if ($hit) {
                DB::table('error_logs')->where('id', $hit->id)->update(['occurrences' => $hit->occurrences + 1, 'last_seen' => $now, 'resolved_at' => null]);

                return;
            }
            DB::table('error_logs')->insert(['fingerprint' => $fp, 'level' => $level, 'source' => $source, 'message' => $message, 'location' => $location ? mb_substr($location, 0, 500) : null, 'url' => $url, 'user_id' => $userId, 'context' => $context ? json_encode($context) : null, 'occurrences' => 1, 'first_seen' => $now, 'last_seen' => $now]);
            if (DB::table('error_logs')->count() > 5000) {
                DB::table('error_logs')->orderBy('last_seen')->limit(500)->delete();
            }
        } catch (Throwable) {
            // logging must never break a request
        }
    }

    public static function exception(Throwable $e, ?int $userId = null, ?string $url = null): void
    {
        self::record('error', 'server', get_class($e).': '.$e->getMessage(), basename($e->getFile()).':'.$e->getLine(), $url, $userId);
    }
}
