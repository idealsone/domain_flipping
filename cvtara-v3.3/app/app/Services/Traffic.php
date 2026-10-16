<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Keeps the site responsive when many people arrive at once. Pages are served from ready-made copies (see PageCache); the work that
 * is expensive for the server — ATS reviews, link reading, PDF and Word files — can be paused for a minute at a time with a polite
 * message while everything else keeps working. Payments, sign-in and the customer's own saved work are never paused.
 */
class Traffic
{
    public static function settings(): array
    {
        return PageCache::settings();
    }

    /** One-minute server load divided by the number of CPU cores, or null when the host does not report it. */
    public static function load(): ?float
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }
        $l = @sys_getloadavg();
        if (! $l) {
            return null;
        }
        $cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cores = max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), "\nprocessor"));
            $cores = max(1, $cores);
        }

        return round($l[0] / $cores, 2);
    }

    public static function busy(): bool
    {
        $s = self::settings();
        if ($s['busyMode'] === 'on') {
            return true;
        }
        if ($s['busyMode'] !== 'auto') {
            return false;
        }

        return Cache::remember('traffic:busy', 5, fn () => ($l = self::load()) !== null && $l >= (float) $s['autoLoad']);
    }

    public static function guardHeavy(): void
    {
        if (self::busy()) {
            abort(503, 'We are very busy right now. Please try again in a minute — your saved work and plan are safe.', ['Retry-After' => '60']);
        }
    }

    public static function status(): array
    {
        $s = self::settings();

        return ['mode' => $s['busyMode'], 'autoLoad' => (float) $s['autoLoad'], 'load' => self::load(), 'busyNow' => self::busy()];
    }
}
