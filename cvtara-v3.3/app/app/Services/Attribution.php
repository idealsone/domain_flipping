<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where a sign-up came from: the first page and source of the visit in which the person signed up. It is recorded only
 * for visitors who allowed analytics (the same consent as visitor counting), so sign-ups without it show as "unknown".
 * Sales are then tied to that page and source through the buyer's account: this is attribution, not proof of cause.
 */
class Attribution
{
    public const SOURCES = ['google', 'bing', 'social', 'referral', 'direct'];

    public const ORGANIC = ['google', 'bing'];

    /** Called on each consented page view: keeps the first one of the session. */
    public static function touch(Request $r, string $path, string $source, ?string $campaign): void
    {
        if ($source === 'internal' || $r->session()->has('firstTouch')) {
            return;
        }
        $r->session()->put('firstTouch', ['path' => mb_substr($path, 0, 200), 'source' => $source, 'campaign' => $campaign ? mb_substr($campaign, 0, 60) : null]);
    }

    /** Copies the visit's first page and source onto a new account. */
    public static function stamp($user, Request $r): void
    {
        $t = (array) $r->session()->get('firstTouch', []);
        if (! in_array($t['source'] ?? '', self::SOURCES, true)) {
            return;
        }
        DB::table('users')->where('id', $user->id)->update(['signup_source' => $t['source'], 'signup_landing' => $t['path'] ?? null, 'signup_campaign' => $t['campaign'] ?? null]);
    }
}
