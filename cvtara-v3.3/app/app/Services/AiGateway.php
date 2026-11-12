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

    /**
     * Model: the given override (Tara's own setting), then, for short routine tasks listed in config ai.quick_purposes,
     * the owner's "quick-task model" (3.48.0, empty = same as the main model), then the owner's AI setting, then the
     * documented default. Writing for customers (reviews, tailoring, letters, chat answers) always uses the main model.
     */
    public static function model(?string $override = null, ?string $purpose = null): string
    {
        $main = trim((string) (TaraAI::config()['model'] ?? '')) ?: (string) config('ai.default_model');
        $quick = trim((string) (TaraAI::config()['quickModel'] ?? ''));

        return trim((string) $override) ?: ($quick !== '' && $purpose !== null && self::quick($purpose) ? $quick : $main);
    }

    /** Whether a purpose is a short routine task that may run on the cheaper quick-task model. */
    public static function quick(string $purpose): bool
    {
        return in_array($purpose, (array) config('ai.quick_purposes', []), true);
    }

    /** Server-side refusal fallback is offered for the larger models, not for Haiku (the request would be refused). */
    public static function fallbacks(string $model): bool
    {
        return ! str_contains($model, 'haiku');
    }

    /** A short fingerprint of a system prompt, stored with each request so a change of wording can be traced in costs. */
    public static function promptVersion(string $system): string
    {
        return substr(sha1($system), 0, 10);
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
    public static function send(string $purpose, ?int $ownerId, callable $build, ?int $timeout = null, ?string $promptVersion = null)
    {
        $t0 = hrtime(true);
        // 3.56.0 A free helper pauses for a customer who reached today's AI cap; paid features are never stopped here.
        if (! CostControls::allowed($purpose, $ownerId)) {
            self::record($purpose, '', 0, 0, $ownerId, 'capped', $t0, 'Daily AI cap for this customer reached', $promptVersion);
            throw new \RuntimeException('This customer reached today\'s AI cap.');
        }
        try {
            $m = $build(self::client($purpose, $timeout));
        } catch (\Throwable $e) {
            self::record($purpose, '', 0, 0, $ownerId, self::status($e), $t0, mb_strimwidth($e->getMessage(), 0, 190, '…'), $promptVersion);
            if (self::status($e) === 'auth') {
                ErrorLog::record('error', 'ai', 'The Claude API key was refused. Open Owner console → Integrations → AI and press Test the key to see why.');
            }
            throw $e;
        }
        $status = ($m->stopReason ?? '') === 'refusal' ? 'refusal' : 'ok';
        self::record($purpose, (string) ($m->model ?? ''), (int) ($m->usage->inputTokens ?? 0), (int) ($m->usage->outputTokens ?? 0), $ownerId, $status, $t0, '', $promptVersion);

        return $m;
    }

    /**
     * Integrations > AI "Test the key" (3.47.0): asks Anthropic directly whether the saved key and model work, with one
     * one-word request, and explains the answer in plain words. The key itself is never returned.
     */
    public static function testKey(): array
    {
        $key = trim((string) (TaraAI::config()['claudeApiKey'] ?? ''));
        $model = self::model();
        if ($key === '') {
            return ['works' => false, 'model' => $model, 'message' => 'No Claude API key is saved. Paste one from console.anthropic.com and press Save provider settings.'];
        }
        $o = env('CLAUDE_API_BASE');
        $base = $o && config('app.env') !== 'production' ? rtrim($o, '/') : 'https://api.anthropic.com';
        $shape = str_starts_with($key, 'sk-ant-api') ? '' : ' The saved key does not start with sk-ant-api, so it may not be an API key (an Admin key or a claude.ai login does not work here).';
        $t0 = hrtime(true);
        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)
                ->post($base.'/v1/messages', ['model' => $model, 'max_tokens' => 1, 'messages' => [['role' => 'user', 'content' => 'Hi']]]);
        } catch (\Throwable $e) {
            return ['works' => false, 'model' => $model, 'message' => 'Anthropic could not be reached from the server ('.mb_strimwidth($e->getMessage(), 0, 120, '…').'). Try again in a minute.'];
        }
        $status = $res->status();
        $said = mb_strimwidth((string) ($res->json('error.message') ?? ''), 0, 200, '…');
        self::record('owner:key_test', $res->successful() ? (string) $res->json('model', $model) : '', (int) $res->json('usage.input_tokens', 0), (int) $res->json('usage.output_tokens', 0), null, $res->successful() ? 'ok' : ($status === 401 || $status === 403 ? 'auth' : 'error'), $t0, $res->successful() ? '' : "HTTP $status: $said");
        $message = match (true) {
            $res->successful() => 'The key works with '.$model.'. Tara and the Business assistant can answer.',
            $status === 401 => 'Anthropic says this key is not valid: it was mistyped, deleted or belongs to a disabled workspace.'.$shape.' Create a new key at console.anthropic.com > API keys, paste it here and press Save provider settings.',
            $status === 403 => 'Anthropic accepted the key but refused the request ('.$said.'). Check the key\'s workspace and its permissions at console.anthropic.com.',
            $status === 404 => 'The key works, but the model "'.$model.'" is not available to it. Empty the Claude model box (or type a model your account offers) and save.',
            $status === 400 && str_contains(strtolower($said), 'credit') => 'The key works, but the Anthropic account has no credit left. Add credit at console.anthropic.com > Billing.',
            $status === 429 || $status === 529 => 'The key works, but Anthropic is busy or the account rate limit is reached. Try again in a minute.',
            default => 'Anthropic answered HTTP '.$status.($said !== '' ? ': '.$said : '').'.',
        };
        if (! $res->successful() && in_array($status, [401, 403], true)) {
            ErrorLog::record('error', 'ai', 'Key test: '.$message);
        }

        return ['works' => $res->successful(), 'model' => $model, 'status' => $status, 'message' => $message];
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

    private static function hasPromptVersion(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('ai_usage', 'prompt_version');
    }

    private static function hasColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('ai_usage', 'status');
    }

    private static function record(string $purpose, string $model, int $in, int $out, ?int $ownerId, string $status, int $t0, string $error = '', ?string $promptVersion = null): void
    {
        try {
            $extra = self::hasColumns() ? ['status' => $status, 'latency_ms' => (int) round((hrtime(true) - $t0) / 1e6), 'request_id' => RequestId::current(), 'error' => $error] : [];
            if ($promptVersion !== null && self::hasPromptVersion()) {
                $extra['prompt_version'] = $promptVersion;
            }
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
            $ok = $rs->whereIn('status', ['ok', 'refusal', 'capped'])->count(); // a refusal or our own cap is the service working as designed
            $lat = $rs->pluck('latency_ms')->filter(fn ($x) => $x !== null)->sort()->values();
            $last = $rs->whereNotIn('status', ['ok', 'refusal', 'capped'])->last();

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
