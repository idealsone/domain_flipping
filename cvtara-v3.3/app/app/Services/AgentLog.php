<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Every task the SEO and growth agents run, with its result or error, so the owner (and the Business assistant) can see
 * what each agent did, what failed and when it last worked. Tasks are counted as work done, never as results.
 */
class AgentLog
{
    public const AGENTS = ['seo' => 'SEO agent', 'growth' => 'Growth agent'];

    /** Runs one task and records it. A failure is logged and returned as ['error' => …], never thrown at the cron. */
    public static function run(string $agent, string $task, callable $fn): array
    {
        $start = microtime(true);
        $at = now();
        try {
            $out = (array) $fn();
            $status = ($out['skipped'] ?? false) ? 'skipped' : 'ok';
            $out['_run'] = self::write($agent, $task, $status, (string) ($out['summary'] ?? ''), '', $at, $start, (array) ($out['_meta'] ?? []));

            return $out;
        } catch (\Throwable $e) {
            $msg = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : class_basename($e).': '.$e->getMessage();
            self::write($agent, $task, 'failed', '', $msg, $at, $start);
            ErrorLog::record('warning', $agent === 'seo' ? 'seo' : 'growth', self::AGENTS[$agent].' task "'.$task.'" failed: '.mb_substr($msg, 0, 300), 'agent:'.$agent.':'.$task.':'.today()->toDateString());

            return ['error' => $msg];
        }
    }

    /**
     * One row per task. Since 3.58.0 each row also carries its run id, the rules/prompt version, the model (empty when no
     * model was used), the sources it read and the knowledge version it worked from (V7 §37), from the task's `_meta`.
     */
    private static function write(string $agent, string $task, string $status, string $summary, string $error, $at, float $start, array $meta = []): string
    {
        $uid = (string) \Illuminate\Support\Str::uuid();
        $row = ['agent' => $agent, 'task' => mb_substr($task, 0, 40), 'status' => $status, 'summary' => mb_substr($summary, 0, 500), 'error' => mb_substr($error, 0, 500),
            'ms' => (int) round((microtime(true) - $start) * 1000), 'started_at' => $at, 'finished_at' => now()];
        if (self::traced()) {
            $row += ['run_uid' => $uid, 'prompt_version' => mb_substr((string) ($meta['promptVersion'] ?? ''), 0, 40), 'model' => mb_substr((string) ($meta['model'] ?? ''), 0, 60),
                'sources_json' => isset($meta['sources']) ? json_encode(array_values(array_slice((array) $meta['sources'], 0, 30))) : null, 'knowledge_version' => $meta['knowledgeVersion'] ?? KnowledgeSync::version()];
        }
        DB::table('agent_runs')->insert($row);
        if (self::traced()) {
            KnowledgeSync::mark($agent);
        }

        return $uid;
    }

    private static ?bool $traced = null;

    private static function traced(): bool
    {
        return self::$traced ??= \Illuminate\Support\Facades\Schema::hasColumn('agent_runs', 'run_uid');
    }

    /** Counts for one agent over the last N days. */
    public static function counts(string $agent, int $days = 7): array
    {
        $rows = DB::table('agent_runs')->where('agent', $agent)->where('started_at', '>=', now()->subDays($days))->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return ['ok' => (int) ($rows['ok'] ?? 0), 'failed' => (int) ($rows['failed'] ?? 0), 'skipped' => (int) ($rows['skipped'] ?? 0)];
    }

    public static function last(string $agent, ?string $status = null): ?object
    {
        return DB::table('agent_runs')->where('agent', $agent)->when($status, fn ($q) => $q->where('status', $status))->orderByDesc('id')->first();
    }

    /** True when a task's most recent run failed (within the last 2 days): the agent is "failing" until it next succeeds. */
    public static function failing(string $agent): array
    {
        $latest = DB::table('agent_runs')->where('agent', $agent)->where('started_at', '>=', now()->subDays(2))->orderByDesc('id')->get(['task', 'status', 'error', 'started_at'])->unique('task');

        return $latest->where('status', 'failed')->map(fn ($r) => ['task' => $r->task, 'error' => $r->error, 'at' => (string) $r->started_at])->values()->all();
    }

    public static function iso($t): ?string
    {
        return $t ? \Carbon\Carbon::parse($t)->toIso8601String() : null;
    }
}
