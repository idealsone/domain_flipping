<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offers and festival campaigns: one live campaign at a time (or scheduled by dates) with a banner, a timed pop-up,
 * an exit-intent pop-up and an idle pop-up, all carrying one coupon that applies at checkout.
 */
class Promos
{
    /** Colour themes: gradient start, end, text colour, accent for buttons, big emoji used as the artwork. */
    public const THEMES = [
        'diwali' => ['#ff8a00', '#c2185b', '#ffffff', '#ffd54a', '🪔'],
        'holi' => ['#ec4899', '#7c3aed', '#ffffff', '#fde047', '🎨'],
        'navratri' => ['#dc2626', '#f59e0b', '#ffffff', '#fff1c2', '🪘'],
        'ganesh' => ['#f97316', '#be123c', '#ffffff', '#fde68a', '🙏'],
        'pooja' => ['#f59e0b', '#b45309', '#ffffff', '#fffbeb', '🌼'],
        'eid' => ['#047857', '#0f766e', '#ffffff', '#fcd34d', '🌙'],
        'pongal' => ['#65a30d', '#ca8a04', '#ffffff', '#fef9c3', '🌾'],
        'onam' => ['#15803d', '#eab308', '#ffffff', '#fef3c7', '🌸'],
        'christmas' => ['#b91c1c', '#166534', '#ffffff', '#fecaca', '🎄'],
        'newyear' => ['#1e1b4b', '#6d28d9', '#ffffff', '#fbbf24', '🎆'],
        'republic' => ['#f97316', '#15803d', '#ffffff', '#ffffff', '🇮🇳'],
        'earlybird' => ['#0ea5e9', '#2258d6', '#ffffff', '#fde047', '🐦'],
        'flash' => ['#111827', '#dc2626', '#ffffff', '#fde047', '⚡'],
        'graduate' => ['#2258d6', '#0891b2', '#ffffff', '#bfdbfe', '🎓'],
        'appraisal' => ['#0f766e', '#2258d6', '#ffffff', '#99f6e4', '💼'],
        'brand' => ['#1557d2', '#2b3a8c', '#ffffff', '#fde047', '🚀'],
    ];

    /** Ready-made starting points. {{year}} is filled in when a campaign is created from a template. */
    public const TEMPLATES = [
        'diwali' => ['Diwali', 'Diwali Dhamaka {{year}}', 'Light up your career. Festive prices on every plan.', 'FESTIVE OFFER', 'diwali'],
        'holi' => ['Holi', 'Holi Hai! Add colour to your career', 'Celebrate with a special price on your next resume.', 'HOLI OFFER', 'holi'],
        'navratri' => ['Navratri', 'Nine nights, nine reasons to upgrade your resume', 'Festive savings for the next nine days only.', 'NAVRATRI OFFER', 'navratri'],
        'ganesh' => ['Ganesh Chaturthi', 'Start your job hunt with Ganpati Bappa’s blessings', 'A festive price to begin something new.', 'GANESH CHATURTHI', 'ganesh'],
        'pooja' => ['Pooja / auspicious day', 'Shubh Muhurat: begin your job search today', 'An auspicious-day price on every plan.', 'SHUBH MUHURAT', 'pooja'],
        'eid' => ['Eid', 'Eid Mubarak! A gift for your career', 'Celebrate with a special price on every plan.', 'EID OFFER', 'eid'],
        'pongal' => ['Pongal / Sankranti', 'Harvest a better job this Pongal', 'Festive prices while the celebrations last.', 'PONGAL OFFER', 'pongal'],
        'onam' => ['Onam', 'Onam offer: grow your career this season', 'Festive prices on every plan.', 'ONAM OFFER', 'onam'],
        'christmas' => ['Christmas', 'Christmas gift: an interview-ready resume', 'Treat yourself to a better application.', 'CHRISTMAS OFFER', 'christmas'],
        'newyear' => ['New Year', 'New year, new job. Start {{year}} with a strong resume', 'New-year price on every plan.', 'NEW YEAR OFFER', 'newyear'],
        'republic' => ['Republic / Independence Day', 'Freedom to choose your next job', 'A national-day price on every plan.', 'NATIONAL DAY OFFER', 'republic'],
        'earlybird' => ['Early-bird', 'Early-bird price for early starters', 'Get the lowest price of the season, then keep it.', 'EARLY BIRD', 'earlybird'],
        'flash' => ['Flash sale', '24-hour flash sale', 'This price disappears when the timer ends.', 'FLASH SALE', 'flash'],
        'graduate' => ['Graduation season', 'Just graduated? Your first resume, ready today', 'A student-friendly price for your first job search.', 'FRESHER OFFER', 'graduate'],
        'appraisal' => ['Appraisal / hiring season', 'Hiring season is here. Be ready first', 'A special price while companies are hiring.', 'HIRING SEASON', 'appraisal'],
        'custom' => ['Blank offer', 'Special offer', 'A limited-time price on every plan.', 'OFFER', 'brand'],
    ];

    public static function all(): array
    {
        $v = Settings::get('promos', ['campaigns' => []]);

        return array_values($v['campaigns'] ?? []);
    }

    public static function put(array $campaigns): void
    {
        Settings::put('promos', ['campaigns' => array_values($campaigns)]);
        self::bump();
    }

    public static function bump(): void
    {
        Cache::forever('promo-ver', microtime(true));
    }

    public static function cacheKey(string $offer): string
    {
        return 'promo-active:'.Cache::get('promo-ver', 0).':'.$offer;
    }

    /** off | live | scheduled | upcoming | ended */
    public static function status(array $c, ?int $now = null): string
    {
        $now ??= time();
        $mode = $c['mode'] ?? 'off';
        $from = ! empty($c['startsAt']) ? strtotime($c['startsAt'].' Asia/Kolkata') : null;
        $to = ! empty($c['endsAt']) ? strtotime($c['endsAt'].' Asia/Kolkata') : null;
        if ($mode === 'off') {
            return 'off';
        }
        if ($to && $now > $to) {
            return 'ended';
        }
        if ($mode === 'live') {
            return 'live';
        }
        if ($from && $now < $from) {
            return 'upcoming';
        }

        return $from || $to ? 'scheduled' : 'off';
    }

    public static function coupon(?string $code): ?array
    {
        if (! $code) {
            return null;
        }
        foreach (BusinessControls::get()['coupons'] as $c) {
            if (strtoupper($c['code']) === strtoupper($code)) {
                return $c;
            }
        }

        return null;
    }

    public static function couponUsable(?array $c): bool
    {
        return $c && ! empty($c['enabled']) && (empty($c['expiresAt']) || $c['expiresAt'] >= now('UTC')->toDateString());
    }

    public static function discountLabel(?array $c): string
    {
        if (! $c) {
            return '';
        }

        return $c['type'] === 'percent' ? $c['value'].'% OFF' : '₹'.number_format($c['value']).' OFF';
    }

    /** What the public page may know about a campaign. */
    public static function view(array $c): array
    {
        $coupon = self::coupon($c['couponCode'] ?? null);
        $theme = self::THEMES[$c['theme'] ?? 'brand'] ?? self::THEMES['brand'];
        $to = ! empty($c['endsAt']) ? strtotime($c['endsAt'].' Asia/Kolkata') : null;
        $vars = ['{{discount}}' => self::discountLabel($coupon), '{{code}}' => strtoupper((string) ($c['couponCode'] ?? '')), '{{year}}' => date('Y')];
        $fill = fn ($s) => strtr((string) $s, $vars);

        return [
            'id' => $c['id'], 'badge' => $fill($c['badge'] ?? ''), 'heading' => $fill($c['heading'] ?? ''), 'subheading' => $fill($c['subheading'] ?? ''), 'body' => $fill($c['body'] ?? ''),
            'cta' => $c['cta'] ?: 'Claim this offer', 'code' => strtoupper((string) ($c['couponCode'] ?? '')), 'discount' => self::discountLabel($coupon), 'plan' => Settings::planAlias((string) ($c['planKey'] ?? '')),
            'image' => $c['imageUrl'] ?? '', 'theme' => ['key' => isset(self::THEMES[$c['theme'] ?? '']) ? $c['theme'] : 'brand', 'from' => $theme[0], 'to' => $theme[1], 'ink' => $theme[2], 'accent' => $theme[3], 'art' => $theme[4]],
            'endsAt' => $to ? gmdate('c', $to) : null,
            'triggers' => ['banner' => ! empty($c['banner']), 'popup' => ['enabled' => ! empty($c['popup']), 'delay' => (int) ($c['popupDelay'] ?? 20)], 'exit' => ! empty($c['exit']), 'idle' => ['enabled' => ! empty($c['idle']), 'seconds' => (int) ($c['idleSeconds'] ?? 45)]],
            'variants' => ['exit' => ['heading' => $fill($c['exitHeading'] ?? ''), 'text' => $fill($c['exitText'] ?? '')], 'idle' => ['heading' => $fill($c['idleHeading'] ?? ''), 'text' => $fill($c['idleText'] ?? '')]],
            'pages' => $c['pages'] ?? 'all', 'frequencyHours' => max(1, (int) ($c['frequencyHours'] ?? 12)),
        ];
    }

    /** The campaign visitors see now: a live one wins, then the scheduled one that started most recently. A campaign whose coupon is not usable is skipped. */
    public static function active(?string $offerCode = null): ?array
    {
        $now = time();
        $usable = function (array $c) {
            $code = $c['couponCode'] ?? '';

            return $code === '' || self::couponUsable(self::coupon($code));
        };
        $list = array_filter(self::all(), fn ($c) => in_array(self::status($c, $now), ['live', 'scheduled'], true) && $usable($c));
        if ($offerCode) {
            foreach ($list as $c) {
                if (strtoupper($c['couponCode'] ?? '') === strtoupper($offerCode)) {
                    return self::view($c);
                }
            }
        }
        usort($list, fn ($a, $b) => [self::status($a, $now) === 'live' ? 0 : 1, -((int) ($a['priority'] ?? 0)), -strtotime(($a['startsAt'] ?? '1970-01-01').' Asia/Kolkata')] <=> [self::status($b, $now) === 'live' ? 0 : 1, -((int) ($b['priority'] ?? 0)), -strtotime(($b['startsAt'] ?? '1970-01-01').' Asia/Kolkata')]);

        return $list ? self::view(reset($list)) : null;
    }

    public static function track(string $id, string $kind): void
    {
        if (! preg_match('/^[a-f0-9-]{36}$/', $id) || ! in_array($kind, ['shown', 'click', 'copy', 'close'], true)) {
            return;
        }
        if (! collect(self::all())->contains('id', $id)) {
            return;
        }
        $day = now()->toDateString();
        if (DB::table('promo_stats')->where(['campaign_id' => $id, 'day' => $day, 'kind' => $kind])->increment('n') === 0) {
            DB::table('promo_stats')->insertOrIgnore(['campaign_id' => $id, 'day' => $day, 'kind' => $kind, 'n' => 1]);
            DB::table('promo_stats')->where(['campaign_id' => $id, 'day' => $day, 'kind' => $kind])->where('n', 0)->increment('n');
        }
    }

    /** @return array<string,array{shown:int,click:int,copy:int,close:int,orders:int,revenuePaise:int}> */
    public static function stats(): array
    {
        $out = [];
        foreach (DB::table('promo_stats')->selectRaw('campaign_id, kind, sum(n) as n')->groupBy('campaign_id', 'kind')->get() as $r) {
            $out[$r->campaign_id][$r->kind] = (int) $r->n;
        }
        foreach (self::all() as $c) {
            $s = $out[$c['id']] ?? [];
            $code = strtoupper($c['couponCode'] ?? '');
            $p = $code ? DB::table('purchases')->where('coupon_code', $code)->whereIn('status', ['paid', 'partially_refunded']) : null;
            $out[$c['id']] = ['shown' => $s['shown'] ?? 0, 'click' => $s['click'] ?? 0, 'copy' => $s['copy'] ?? 0, 'close' => $s['close'] ?? 0, 'orders' => $p ? (int) (clone $p)->count() : 0, 'revenuePaise' => $p ? (int) (clone $p)->sum('amount_paise') : 0];
        }

        return $out;
    }

    public static function newId(): string
    {
        return (string) Str::uuid();
    }
}
