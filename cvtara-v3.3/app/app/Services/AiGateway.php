<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Http\Middleware\RequestId;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single way CVTara talks to Claude (3.38.0). One client factory, one place for the model choice, timeouts and
 * retries (config/ai.php), and every request, successful or not, is recorded in ai_usage with its outcome, how long it
 * took and the request reference. AI health (success rate, slow requests, last error per assistant) is read from there.
 */
class AiGateway
{
    public const STATUSES = ['ok', 'refusal', 'empty', 'rate_limit', 'timeout', 'auth', 'error'];

    /** Settings for one assistant: exact purpose, then the part before ':', then 'default'. */
    public static function assistant(string $purpose): array
    {
        $a = (array) config('ai.assistants', []);
        $base = (array) ($a['default'] ?? []) + ['timeout' => 50, 'retries' => 0];

        return (array) ($a[$purpose] ?? $a[strstr($purpose, ':', true) ?: $purpose] ?? []) + $base;
    }

    /** Model: the given override (Tara's own setting), then the owner's AI setting, then the documented default. */
    public static function model(?string $override = null): string
    {
        return trim((string) $override) ?: (trim((string) (TaraAI::config()['model'] ?? '')) ?: (string) config('ai.default_model'));
    }

    /** The Claude API address. Tests may point it at a local stand-in, never on a production site. */
    public static function client(string $purpose, ?int $timeout = null): Client
    {
        $a = self::assistant($purpose);
        $o = env('CLAUDE_API_BASE');
        $seconds = max(1, $timeout ?? (int) $a['timeout']);

        // The SDK does not enforce 'timeout' itself; the HTTP client does. Without this a stuck request would hold a
        // PHP worker until the server killed it.
        return new Client(
            apiKey: (string) (TaraAI::config()['claudeApiKey'] ?? ''),
            baseUrl: $o && config('app.env') !== 'production' ? rtrim($o, '/') : 'https://api.anthropic.com',
            requestOptions: ['timeout' => $seconds, 'maxRetries' => (int) $a['retries'], 'transporter' => new \GuzzleHttp\Client(['timeout' => $seconds, 'connect_timeout' => min(10, $seconds)])],
        );
    }

    /**
     * Sends one request: $build receives the client and returns the API message. The outcome is recorded either way;
     * errors are rethrown unchanged so each feature keeps its own customer wording.
     */
    public static function send(string $purpose, ?int $ownerId, callable $build, ?int $timeout = null)
    {
        $t0 = hrtime(true);
        try {
            $m = $build(self::client($purpose, $timeout));
        } catch (\Throwable $e) {
            self::record($purpose, '', 0, 0, $ownerId, self::status($e), $t0, mb_strimwidth($e->getMessage(), 0, 190, '…'));
            if (self::status($e) === 'auth') {
                ErrorLog::record('error', 'ai', 'The Claude API key was refused. Check it in Owner console → Settings → Integrations → AI.');
            }
            throw $e;
        }
        $status = ($m->stopReason ?? '') === 'refusal' ? 'refusal' : 'ok';
        self::record($purpose, (string) ($m->model ?? ''), (int) ($m->usage->inputTokens ?? 0), (int) ($m->usage->outputTokens ?? 0), $ownerId, $status, $t0);

        return $m;
    }

    /** Marks a request that came back without usable text (recorded as ok by send()) as empty. */
    public static function markEmpty(): void
    {
        try {
            if (self::hasColumns()) {
                DB::table('ai_usage')->where('request_id', RequestId::current())->where('status', 'ok')->orderByDesc('id')->limit(1)->update(['status' => 'empty']);
            }
        } catch (\Throwable) {
        }
    }

    public static function status(\Throwable $e): string
    {
        return match (true) {
            $e instanceof APIStatusException && in_array($e->status ?? 0, [401, 403], true) => 'auth',
            $e instanceof RateLimitException, $e instanceof APIStatusException && in_array($e->status ?? 0, [429, 529], true) => 'rate_limit',
            $e instanceof APIConnectionException => 'timeout',
            $e->getMessage() === 'refusal' => 'refusal',
            $e->getMessage() === 'empty' => 'empty',
            default => 'error',
        };
    }

    private static function hasColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('ai_usage', 'status');
    }

    private static function record(string $purpose, string $model, int $in, int $out, ?int $ownerId, string $status, int $t0, string $error = ''): void
    {
        try {
            $extra = self::hasColumns() ? ['status' => $status, 'latency_ms' => (int) round((hrtime(true) - $t0) / 1e6), 'request_id' => RequestId::current(), 'error' => $error] : [];
            Growth::recordAi($purpose, $model, $in, $out, $ownerId, $extra);
        } catch (\Throwable $e) {
            ErrorLog::exception($e); // counting must never cost the customer their answer
        }
    }

    /** Last 24 hours, overall and per assistant: requests, success rate, 95th-percentile time and the last error. */
    public static function health(): array
    {
        if (! Schema::hasTable('ai_usage') || ! self::hasColumns()) {
            return ['enabled' => TaraAI::enabled(), 'calls' => 0, 'successPct' => null, 'p95Ms' => null, 'status' => 'unknown', 'assistants' => []];
        }
        $rows = DB::table('ai_usage')->where('created_at', '>=', now()->subDay())->orderBy('id')->get(['purpose', 'status', 'latency_ms', 'error', 'created_at']);
        $sum = function ($rs) {
            $n = $rs->count();
            $ok = $rs->whereIn('status', ['ok', 'refusal'])->count(); // a refusal is the service working as designed
            $lat = $rs->pluck('latency_ms')->filter(fn ($x) => $x !== null)->sort()->values();
            $last = $rs->whereNotIn('status', ['ok', 'refusal'])->last();

            return ['calls' => $n, 'successPct' => $n ? round($ok / $n * 100, 1) : null,
                'p95Ms' => $lat->count() ? (int) $lat[(int) max(0, ceil($lat->count() * 0.95) - 1)] : null,
                'lastError' => $last ? ['at' => (string) $last->created_at, 'status' => $last->status, 'message' => (string) $last->error] : null];
        };
        $all = $sum($rows);
        $h = (array) config('ai.health');
        $status = ! $all['calls'] ? 'idle' : ($all['calls'] >= (int) ($h['min_calls'] ?? 20) && $all['successPct'] < (float) ($h['min_success_pct'] ?? 80) ? 'failing' : ($all['successPct'] < 95 ? 'attention' : 'healthy'));
        if ($rows->where('status', 'auth')->count() && $rows->last()?->status === 'auth') {
            $status = 'failing';
        }

        return ['enabled' => TaraAI::enabled(), 'status' => $status] + $all + ['assistants' => $rows->groupBy(fn ($r) => (string) $r->purpose)->map($sum)->all()];
    }

    /** From the cron, at most once an hour: a failing AI service goes to the Error Log (one row per day, counting hours). */
    public static function watch(): ?array
    {
        if (! Cache::add('ai-health-watch', 1, 3600)) {
            return null;
        }
        $h = self::health();
        if ($h['status'] === 'failing') {
            ErrorLog::record('warning', 'ai', 'The AI service is failing: '.($h['successPct'] ?? 0).'% of '.$h['calls'].' requests in the last 24 hours succeeded'.($h['lastError'] ? ' (last problem: '.$h['lastError']['status'].')' : '').'. Customers see a plain "try again later" message. See Setup & health.', 'ai-health:'.now()->toDateString());
        }

        return $h;
    }
}
