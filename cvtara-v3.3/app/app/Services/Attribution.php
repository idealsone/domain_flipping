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
    public const SOURCES = ['google', 'bing', 'ai', 'social', 'referral', 'direct'];

    public const ORGANIC = ['google', 'bing'];

    /**
     * Called on each consented page view: keeps the first one of the session (first touch) and the latest outside arrival
     * (last touch: a new source or campaign, not a click inside the site). A direct visit never replaces a known last touch.
     */
    public static function touch(Request $r, string $path, string $source, ?string $campaign, ?string $host = null): void
    {
        if ($source === 'internal') {
            return;
        }
        $t = ['path' => mb_substr($path, 0, 200), 'source' => $source, 'campaign' => $campaign ? mb_substr($campaign, 0, 60) : null, 'host' => $host ? mb_substr($host, 0, 120) : null];
        if (! $r->session()->has('firstTouch')) {
            $r->session()->put('firstTouch', $t);
        }
        $last = (array) $r->session()->get('lastTouch', []);
        if (! $last || $source !== 'direct' || ($last['source'] ?? '') === 'direct') {
            $r->session()->put('lastTouch', $t);
        }
    }

    /** Copies the last touch of the visit onto an order when checkout starts (first touch stays on the account). */
    public static function stampPurchase(string $purchaseId): void
    {
        try {
            $r = request();
            $t = $r->hasSession() ? (array) $r->session()->get('lastTouch', []) : [];
        } catch (\Throwable) {
            return;
        }
        if (in_array($t['source'] ?? '', self::SOURCES, true)) {
            DB::table('purchases')->where('id', $purchaseId)->update(['touch_source' => $t['source'], 'touch_landing' => $t['path'] ?? null, 'touch_campaign' => $t['campaign'] ?? null]);
        }
    }

    /** The source of an arrival, from the referring site's host name (worked out on the server, so public scripts name no platforms). */
    public static function classify(string $source, ?string $host, ?string $utmSource = null): string
    {
        if ($source === 'internal') {
            return $source;
        }
        if (AiVisibility::platformForHost((string) $host) || AiVisibility::platformForHost((string) $utmSource)) {
            return 'ai';
        }

        return $source;
    }

    /** Copies the visit's first page and source onto a new account. */
    public static function stamp($user, Request $r): void
    {
        $t = (array) $r->session()->get('firstTouch', []);
        if (! in_array($t['source'] ?? '', self::SOURCES, true)) {
            return;
        }
        DB::table('users')->where('id', $user->id)->update(['signup_source' => $t['source'], 'signup_landing' => $t['path'] ?? null, 'signup_campaign' => $t['campaign'] ?? null, 'signup_ref_host' => $t['host'] ?? null]);
    }
    /** Version of the rules that decide acq_class / acq_subtype at sign-up (stored on every account it decides). */
    public const RULES = 'v7-1';

    public const CLASSES = ['b2c', 'b2b'];

    public const SUBTYPES = ['b2c' => ['direct', 'referral', 'influencer'], 'b2b' => ['college', 'coaching', 'corporate', 'partner']];

    /**
     * Decides once, at sign-up, where the account came from (V7 WS-02) and locks it: later visits, clicks or links never
     * change it. Order: a partner campaign link, then a customer's referral link (an approved creator counts as an
     * influencer), otherwise direct. Safe to call twice: an account that already has a decision keeps it.
     */
    public static function lock($user, ?Request $r = null): void
    {
        $row = DB::table('users')->where('id', $user->id)->first(['acq_locked_at', 'referred_by', 'created_at']);
        if (! $row || $row->acq_locked_at) {
            return;
        }
        $d = ['acq_class' => 'b2c', 'acq_subtype' => 'direct', 'acq_partner_id' => null, 'acq_campaign' => null, 'acq_link' => null, 'acq_first_seen_at' => null];
        $partner = $r ? self::partnerFrom($r) : null;
        if ($partner) {
            $d = $partner + $d;
            if ($row->referred_by) {
                // The partner link was the later click: the partner gets the sign-up, so no customer referral reward too.
                $d += ['referred_by' => null, 'referral_attached_at' => null];
            }
        } elseif ($row->referred_by) {
            $creator = DB::table('creator_applications')->where('owner_id', $row->referred_by)->where('status', 'approved')->exists();
            $d['acq_subtype'] = $creator ? 'influencer' : 'referral';
            $at = $r && $r->hasSession() ? $r->session()->get('referral.at') : null;
            $d['acq_first_seen_at'] = $at ? date('Y-m-d H:i:s', (int) $at) : null;
        }
        if (! $d['acq_campaign'] && $r && $r->hasSession()) {
            $t = (array) $r->session()->get('firstTouch', []);
            $d['acq_campaign'] = isset($t['campaign']) ? mb_substr((string) $t['campaign'], 0, 80) : null;
        }
        DB::table('users')->where('id', $user->id)->whereNull('acq_locked_at')->update($d + ['acq_rule_version' => self::RULES, 'acq_locked_at' => now()]);
    }

    /** A partner campaign link seen in the last 30 days (PartnerHub), as acquisition fields, or null. */
    private static function partnerFrom(Request $r): ?array
    {
        return Partners::attributionFor($r);
    }

    /** Readable label for the Users list and exports. */
    public static function label(?string $class, ?string $subtype): string
    {
        if (! $class) {
            return 'Unknown';
        }

        return strtoupper($class).' · '.ucfirst((string) $subtype);
    }
}
