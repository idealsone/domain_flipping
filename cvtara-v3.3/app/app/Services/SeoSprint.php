<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Index sprint (3.68.0, owner request 2026-11-12): for 30 days the SEO and Growth agents do about ten times their usual
 * daily work, so the ~600 public pages get read, checked and offered to every search engine as fast as the engines allow.
 *
 * What is multiplied: pages scanned, Google URL Inspection checks (Google allows 2,000 a day per site; the sprint stays at
 * 600), recrawl requests for changed pages, and how many title/link proposals the SEO agent drafts. What is NOT multiplied:
 * the Google Indexing API (Google's own 200-a-day quota), outreach sends (each still needs the owner's approval), and any
 * live page change (every change is still an approval card that names the page, the section and the exact text).
 *
 * The sprint ends by itself after 30 days. The owner can stop it early from Growth > SEO agent.
 */
class SeoSprint
{
    public const DAYS = 30;

    /** Multipliers per setting, and a hard ceiling so a busy day cannot hurt the host or break an engine's quota. */
    public const BOOST = [
        'scanPerRun' => [5, 200], 'inspectPerRun' => [4, 60], 'inspectPerDay' => [10, 600], 'requestPerRun' => [5, 50],
        'metaPerDay' => [5, 50], 'linksPerDay' => [5, 50],
    ];

    /** The SEO agent drafts no more proposals while this many already wait, so the owner's approval list stays readable. */
    public const MAX_PENDING = 60;

    public static function state(): array
    {
        return array_merge(['on' => false, 'startedAt' => null, 'until' => null, 'by' => null], (array) Settings::get('seoSprint', []));
    }

    public static function active(?Carbon $now = null): bool
    {
        $s = self::state();

        return $s['on'] && $s['until'] && ($now ?? now())->lt(Carbon::parse($s['until']));
    }

    public static function start(?int $userId = null): array
    {
        $s = ['on' => true, 'startedAt' => now()->toIso8601String(), 'until' => now()->addDays(self::DAYS)->toIso8601String(), 'by' => $userId];
        Settings::put('seoSprint', $s);

        return self::status();
    }

    public static function stop(): array
    {
        Settings::put('seoSprint', ['on' => false] + self::state());

        return self::status();
    }

    /** The usual limits, raised while the sprint runs. Keys that are not boosted pass through unchanged. */
    public static function apply(array $limits): array
    {
        if (! self::active()) {
            return $limits;
        }
        foreach (self::BOOST as $k => [$times, $ceiling]) {
            if (isset($limits[$k]) && is_numeric($limits[$k])) {
                $limits[$k] = (int) min($ceiling, max((int) $limits[$k], (int) $limits[$k] * $times));
            }
        }
        if (isset($limits['minImpressions'])) {
            $limits['minImpressions'] = min((int) $limits['minImpressions'], 5); // new site: act on smaller signals
        }

        return $limits;
    }

    /** Day of the sprint (1-30), or 0 when it is not running. */
    public static function day(?Carbon $now = null): int
    {
        $s = self::state();
        if (! self::active($now) || ! $s['startedAt']) {
            return 0;
        }

        return min(self::DAYS, (int) floor(Carbon::parse($s['startedAt'])->diffInDays($now ?? now())) + 1);
    }

    /** The 30-day plan: what each week is for, so the daily runs and the owner's to-dos follow one order. */
    public static function plan(): array
    {
        $f = resource_path('data/seo-sprint.json');

        return is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
    }

    public static function status(): array
    {
        $s = self::state();
        $day = self::day();
        $plan = self::plan();
        $week = $day ? (int) ceil($day / 7) : 0;
        $base = array_merge(array_replace(SeoOps::DEFAULTS, array_intersect_key((array) Settings::get('seoOps', []), SeoOps::DEFAULTS)),
            array_replace(SeoAgent::DEFAULTS, array_intersect_key((array) Settings::get('seoAgent', []), SeoAgent::DEFAULTS)));

        return ['active' => self::active(), 'day' => $day, 'days' => self::DAYS, 'startedAt' => $s['startedAt'], 'until' => $s['until'],
            'limits' => ['usual' => array_intersect_key($base, self::BOOST), 'now' => self::apply(array_intersect_key($base, self::BOOST))],
            'week' => $week ? ($plan['weeks'][min($week, count($plan['weeks'] ?? [1])) - 1] ?? null) : null, 'plan' => $plan];
    }
}
