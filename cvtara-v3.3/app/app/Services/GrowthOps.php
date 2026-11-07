<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The growth agent's working parts (3.34.0): the full customer journey in 11 steps, a revenue diagnosis that names the
 * likely cause with its evidence, the fix, the expected effect and how to measure it, the campaign register (every
 * channel, with audience, offer, landing page, cost and result) and the experiment register (baseline against result).
 *
 * Confirmed revenue (paid orders) is always kept apart from attributed revenue (sales by people a campaign or page
 * reached). A number the site cannot measure is null and is shown as "not tracked".
 */
class GrowthOps
{
    public const STEPS = [
        'visitors' => 'Visitors', 'engaged' => 'Viewed 2+ pages', 'signups' => 'Signed up', 'verified' => 'Verified email', 'used' => 'Built a resume or ran an ATS check',
        'paidStep' => 'Reached a paid step (download wall or checkout)', 'checkout' => 'Opened checkout', 'paid' => 'Paid', 'usedAfter' => 'Used the product after paying',
        'repeat' => 'Bought again', 'upsell' => 'Bought an add-on or bigger pack',
    ];

    public const CHANNELS = ['email' => 'Email campaign', 'outreach' => 'Outreach (cvtara.in)', 'seo' => 'SEO landing page', 'social' => 'Social media', 'linkedin' => 'LinkedIn', 'tpo' => 'College / TPO partnership',
        'coaching' => 'Coaching institute', 'influencer' => 'Influencer / affiliate', 'referral' => 'Referral programme', 'retargeting' => 'Retargeting', 'promotion' => 'Promotion / offer', 'reactivation' => 'Customer reactivation'];

    /** What an experiment can measure: each is counted by the database for any date range. */
    public const METRICS = ['signupRate' => 'Sign-up rate (sign-ups ÷ visitors)', 'signups' => 'Sign-ups', 'activation' => 'Activation (resume or ATS ÷ sign-ups)', 'checkoutRate' => 'Checkout rate (checkouts ÷ sign-ups)',
        'payRate' => 'Payment rate (paid ÷ checkouts)', 'purchases' => 'Paid orders', 'netPaise' => 'Confirmed revenue', 'aovPaise' => 'Average order value', 'repeat' => 'Repeat purchases'];

    public const DEFAULTS = ['auto' => true];

    public static function settings(): array
    {
        return array_replace(self::DEFAULTS, array_intersect_key((array) Settings::get('growthOps', []), self::DEFAULTS));
    }

    private static function utc(Carbon $c): Carbon
    {
        return $c->copy()->utc();
    }

    /**
     * The 11 steps. Visitors are counted per visit in the period; every later step follows the people who signed up
     * in the period up to today (a cohort), so a recent period is still filling in.
     */
    public static function funnel(Carbon $from, Carbon $to): array
    {
        [$a, $b] = [self::utc($from), self::utc($to)];
        $analytics = (bool) (Settings::get('integrations')['analytics'] ?? false);
        $pv = DB::table('page_views')->where('created_at', '>=', $a)->where('created_at', '<', $b);
        $cohort = DB::table('users')->where('is_admin', false)->where('created_at', '>=', $a)->where('created_at', '<', $b);
        $ids = (clone $cohort)->pluck('id');
        $in = fn ($q, $col = 'owner_id') => $q->whereIn($col, $ids);
        $sales = $in(Growth::sales())->get(['owner_id', 'plan', 'amount_paise', 'purchased_at']);
        $first = $sales->groupBy('owner_id')->map(fn ($g) => $g->sortBy('purchased_at')->first());
        $usedAfter = 0;
        foreach ($first as $o => $p) {
            $usedAfter += DB::table('product_usage')->where('owner_id', $o)->where('created_at', '>', $p->purchased_at)->exists() || DB::table('ats_scans')->where('owner_id', $o)->where('created_at', '>', $p->purchased_at)->exists() ? 1 : 0;
        }
        $upsell = $sales->groupBy('owner_id')->filter(function ($g) {
            $s = $g->sortBy('purchased_at')->values();

            return $s->slice(1)->contains(fn ($p) => str_starts_with($p->plan, 'addon-') || $p->amount_paise > $s[0]->amount_paise);
        })->count();
        $checkout = $in(DB::table('purchases')->where('provider', '!=', 'referral_reward')->where('amount_paise', '>', 0))->distinct()->pluck('owner_id');
        $wall = $in(DB::table('mail_outbox')->where('trigger', 'download_locked'))->distinct()->pluck('owner_id');
        $used = $in(DB::table('resume_drafts'))->distinct()->pluck('owner_id')->merge($in(DB::table('ats_scans'))->distinct()->pluck('owner_id'))->unique();

        $steps = [
            'visitors' => $analytics ? (clone $pv)->distinct()->count('visitor_id') : null,
            'engaged' => $analytics ? (clone $pv)->select('visitor_id')->groupBy('visitor_id')->havingRaw('count(*) > 1')->get()->count() : null,
            'signups' => $ids->count(),
            'verified' => (clone $cohort)->whereNotNull('email_verified_at')->count(),
            'used' => $used->count(),
            'paidStep' => $checkout->merge($wall)->unique()->count(),
            'checkout' => $checkout->count(),
            'paid' => $first->count(),
            'usedAfter' => $usedAfter,
            'repeat' => $sales->groupBy('owner_id')->filter(fn ($g) => $g->count() > 1)->count(),
            'upsell' => $upsell,
        ];
        $rows = [];
        $prev = null;
        foreach (self::STEPS as $k => $label) {
            $rows[] = ['key' => $k, 'label' => $label, 'value' => $steps[$k], 'fromPrevious' => $prev !== null && $steps[$k] !== null && $steps[$prev] ? round($steps[$k] / $steps[$prev] * 100, 1) : null, 'lost' => $prev !== null && $steps[$k] !== null && $steps[$prev] !== null ? max(0, $steps[$prev] - $steps[$k]) : null];
            $prev = $k;
        }

        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'steps' => $rows, 'revenuePaise' => (int) $sales->sum('amount_paise'),
            'note' => ($analytics ? 'Visitors count only people who allowed analytics. ' : 'Visitors are not tracked until Analytics is switched on in Integrations. ').'From sign-up on, the steps follow the people who signed up in this period up to today. "Reached a paid step" counts people who hit the download wall or opened checkout; looking at the plans section alone is not tracked.'];
    }

    /** Numbers for one experiment metric over a date range. */
    public static function metric(string $key, Carbon $from, Carbon $to): ?float
    {
        $m = Growth::metrics($from, $to);
        $f = self::funnel($from, $to)['steps'];
        $v = array_column($f, 'value', 'key');

        return match ($key) {
            'signupRate' => $m['visitors'] ? round($m['signups'] / $m['visitors'] * 100, 2) : null,
            'signups' => (float) $m['signups'],
            'activation' => $v['signups'] ? round($v['used'] / $v['signups'] * 100, 2) : null,
            'checkoutRate' => $v['signups'] ? round($v['checkout'] / $v['signups'] * 100, 2) : null,
            'payRate' => $m['checkouts'] ? round($m['buyers'] / $m['checkouts'] * 100, 2) : null,
            'purchases' => (float) $m['purchases'],
            'netPaise' => (float) $m['netPaise'],
            'aovPaise' => $m['purchases'] ? round($m['grossPaise'] / $m['purchases']) : null,
            'repeat' => (float) $m['repeatPurchases'],
            default => null,
        };
    }

    // ---------------------------------------------------------------- diagnosis

    /**
     * Why revenue is where it is. Each finding names the likely cause, the evidence, the fix, the expected effect and how
     * to check it worked. Findings need a minimum number of people; below that the agent says there is not enough data.
     */
    public static function diagnose(int $days = 30): array
    {
        $now = now()->tz(Growth::TZ);
        $from = $now->copy()->subDays($days)->startOfDay();
        $f = self::funnel($from, $now);
        $v = array_column($f['steps'], 'value', 'key');
        $m = Growth::metrics($from, $now);
        $avg = (int) Growth::settings()['avgPaise'];
        $out = [];
        $add = function (string $area, string $sev, string $finding, string $cause, array $evidence, string $action, string $impact, string $measure, ?int $atStake = null) use (&$out) {
            $out[] = compact('area', 'sev', 'finding', 'cause', 'evidence', 'action', 'impact', 'measure') + ['atStakePaise' => $atStake];
        };
        $pct = fn ($a, $b) => $b ? round($a / $b * 100, 1) : null;

        // 1. Visitors who do not sign up, and which first pages bring traffic but no sign-ups.
        if ($v['visitors'] !== null && $v['visitors'] >= 100) {
            $rate = $pct($v['signups'], $v['visitors']);
            if ($rate < 3) {
                $add('Acquisition', $rate < 1 ? 'high' : 'medium', "Only $rate% of visitors sign up", 'The pages people land on do not lead clearly to a free account, or the visitors are looking for something else (often information only).',
                    ["{$v['visitors']} visitors and {$v['signups']} sign-ups in $days days (visitors who allowed analytics)."],
                    'On the landing pages with the most visitors, put one clear "Build my resume free" button above the fold and repeat it after the first section.', 'Each extra percentage point of sign-ups adds about '.max(1, (int) round($v['visitors'] / 100)).' accounts a month at today\'s traffic.',
                    'Sign-up rate on those pages for 14 days before and after (Experiments).');
            }
        }
        $lp = SeoOps::landingPages($days);
        $dead = array_values(array_filter($lp, fn ($r) => ($r['visitors'] ?? 0) >= 50 && $r['signups'] === 0));
        if ($dead) {
            $add('Landing pages', 'medium', count($dead).' page(s) get visitors but no sign-ups', 'The page answers the visitor\'s question without showing why an account helps, or the sign-up link is missing or low on the page.',
                array_map(fn ($r) => $r['page'].': '.$r['visitors'].' visitors, 0 sign-ups', array_slice($dead, 0, 5)),
                'Add a short "Use this with your own resume" block with a sign-up button to these pages (it changes page content, so it is your call).', 'Turns existing traffic into accounts without new spend.', 'Sign-ups whose first page is one of these, compared month to month.');
        }
        $noSales = array_values(array_filter($lp, fn ($r) => $r['signups'] >= 10 && $r['buyers'] === 0));
        if ($noSales) {
            $add('Landing pages', 'medium', count($noSales).' page(s) bring sign-ups but no sales', 'The people these pages attract are often students or browsers who do not need a paid download yet, or the page sets an expectation of everything free.',
                array_map(fn ($r) => $r['page'].': '.$r['signups'].' sign-ups, 0 buyers', array_slice($noSales, 0, 5)),
                'Say early on these pages what is free and what the ₹99 single download gives, and follow up these sign-ups with the plan guide (consenting users only).', 'Better-matched expectations raise paid conversion of these sign-ups.', 'Buyers among sign-ups from these pages, next 30 days.');
        }
        // 2. Verification and activation.
        if ($v['signups'] >= 20) {
            $ver = $pct($v['verified'], $v['signups']);
            if ($ver < 70) {
                $add('Activation', 'high', "Only $ver% of new accounts verify their email", 'The sign-up code mail is slow, lands in spam, or people leave before entering it.',
                    ["{$v['verified']} of {$v['signups']} sign-ups verified.", 'Emails failed in the last 24 hours: '.(Health::snapshot()['metrics']['mailFailed24h'] ?? 'unknown').'.'],
                    'Send a test sign-up to Gmail and Outlook, check the code mail lands in the inbox within a minute (Health page), and make the code screen say "check spam".', 'Every unverified account is a lost customer; fixing this lifts every later step.', 'Verified ÷ sign-ups, weekly.');
            }
            $act = $pct($v['used'], $v['signups']);
            if ($act < 50) {
                $add('Activation', 'medium', "Only $act% of new accounts build a resume or run an ATS check", 'The first screen after sign-up asks for too much, or does not show the quickest win (upload an old resume or run a free ATS check).',
                    ["{$v['used']} of {$v['signups']} sign-ups used the product."], 'Make "Upload my resume" and "Free ATS check" the two first buttons after sign-up and send the welcome mail sooner.', 'People who build a resume are the only ones who buy downloads.', 'Activation ÷ sign-ups, weekly (Experiments).');
            }
        }
        // 3. Free users who do not buy.
        if ($v['used'] >= 30) {
            $buy = $pct($v['paid'], $v['used']);
            if ($buy < 5) {
                $add('Conversion', 'high', "Only $buy% of active free users buy", 'Free users get what they need without paying, the value of a clean download is unclear, or price or trust holds them back.',
                    ["{$v['paid']} buyers among {$v['used']} active new users.", "{$v['paidStep']} reached the download wall or checkout."],
                    'Show the ₹99 single download right where the watermark appears, with a sample of the clean PDF, the 7-day refund promise and real reviews.', 'At ₹'.round($avg / 100).' an order, each extra point of conversion is worth about ₹'.number_format($v['used'] * $avg / 10000).' a month at this volume.', 'Paid ÷ active users, per cohort.', (int) round($v['used'] * 0.01 * $avg));
            }
        }
        // 4. Checkout abandonment and payment failures.
        if ($m['checkouts'] >= 10) {
            $pay = $pct($m['buyers'], $m['checkouts']);
            if ($pay < 60) {
                $unpaid = $m['checkouts'] - $m['buyers'];
                $fails = DB::table('error_logs')->where('source', 'payment')->where('last_seen', '>=', $from->copy()->utc())->sum('occurrences');
                $add('Checkout', 'high', "Only $pay% of people who open checkout pay", 'Payment fails (UPI timeouts, gateway errors), or the price at checkout surprises people.',
                    ["{$m['checkouts']} opened checkout, {$m['buyers']} paid.", "Payment errors logged: $fails.", 'Gateways switched on: '.implode(', ', (array) (Settings::get('payments')['gateways'] ?? [])) ?: 'unknown'],
                    'Make a real ₹99 test payment on a phone with UPI on each gateway, check the Error Log for payment errors, and make sure the unfinished-payment reminder is on.', $unpaid.' unpaid checkouts are worth about ₹'.number_format($unpaid * $avg / 100).'.', 'Paid ÷ checkouts, weekly.', $unpaid * $avg);
            }
        }
        // 5. Refunds.
        if ($m['purchases'] >= 10 && $m['refundedPaise'] > $m['grossPaise'] * 0.08) {
            $reasons = DB::table('refund_requests')->where('created_at', '>=', $from->copy()->utc())->whereNotNull('reason')->pluck('reason')->map(fn ($r) => mb_strimwidth((string) $r, 0, 80, '…'))->take(5)->all();
            $add('Trust', 'medium', 'Refunds are '.$pct($m['refundedPaise'], $m['grossPaise']).'% of sales', 'Customers did not get what they expected from the pack they bought.', array_merge(['Refunded ₹'.number_format($m['refundedPaise'] / 100).' of ₹'.number_format($m['grossPaise'] / 100).'.'], $reasons),
                'Read the refund reasons, then fix the plan description or the feature they mention before buying more traffic.', 'Lower refunds protect revenue already earned.', 'Refunded ÷ gross, monthly.', $m['refundedPaise']);
        }
        // 6. Renewal and repeat.
        $ended = DB::table('purchases')->whereNotNull('access_until')->whereBetween('access_until', [$from->copy()->utc(), now()])->where('amount_paise', '>', 0)->whereIn('status', ['paid', 'captured'])->distinct()->pluck('owner_id');
        if ($ended->count() >= 10) {
            $back = Growth::sales()->whereIn('owner_id', $ended)->where('purchased_at', '>=', $from->copy()->utc())->distinct()->count('owner_id');
            $r = $pct($back, $ended->count());
            if ($r < 20) {
                $add('Retention', 'medium', "Only $r% of customers whose plan ended bought again", 'Most people buy for one job search; when it ends they have no reason to come back, or the win-back mail does not reach them.',
                    [$ended->count()." plans ended in $days days; $back of those customers bought again."], 'Offer a short top-up (single download) in the "your work is still saved" mail and remind them before a plan ends (consenting users only).', 'Repeat buyers cost nothing to acquire.', 'Repeat buyers ÷ plans ended, monthly.');
            }
        }
        // 7. Campaigns with sends but no sales.
        foreach (DB::table('email_campaigns')->whereNotNull('started_at')->where('started_at', '>=', $from->copy()->utc())->get(['id', 'name', 'started_at']) as $c) {
            $st = Campaigns::stats($c->id);
            if ($st['sent'] >= 100) {
                $buyers = Growth::sales()->whereIn('owner_id', DB::table('campaign_recipients')->where('campaign_id', $c->id)->whereNotNull('owner_id')->select('owner_id'))->where('purchased_at', '>=', $c->started_at)->where('purchased_at', '<', Carbon::parse($c->started_at)->addDays(14))->distinct()->count('owner_id');
                if ($buyers === 0) {
                    $add('Campaigns', 'medium', 'Campaign "'.$c->name.'" sold nothing in 14 days', 'The audience, the offer or the call to action did not match; opens and clicks are not tracked, so the exact step is unknown.',
                        [$st['sent'].' sent, '.$st['unsubscribed'].' unsubscribed, 0 buyers within 14 days.'], 'Do not resend it. Write two new versions with one clear offer and one link each, send each to a small part of the list, and keep the better one (log it as an experiment).', 'Avoids burning the list with a message that does not work.', 'Buyers within 14 days per version.');
                }
            }
        }
        // 8. Outreach: deliverability, permission and response.
        foreach (Outreach::LISTS as $list => $label) {
            $c = DB::table('outreach_contacts')->where('list', $list);
            $sent = DB::table('outreach_sends')->where('kind', 'campaign')->where('status', 'sent')->where('created_at', '>=', $from->copy()->utc())->whereIn('contact_id', (clone $c)->select('id'))->count();
            if ($sent < 100) {
                continue;
            }
            $bounced = (clone $c)->where('status', 'bounced')->count();
            $clicked = (clone $c)->whereNotNull('clicked_at')->count();
            $reg = (clone $c)->whereNotNull('user_id')->count();
            $total = (clone $c)->count();
            $noSource = (clone $c)->where('source', '')->count();
            $ev = ["$sent mails sent in $days days; $total contacts on the list.", "Bounced: $bounced (".$pct($bounced, $total).'%). Clicked a link: '.$clicked.'. Signed up: '.$reg.'.'];
            if ($noSource) {
                $ev[] = "$noSource contacts have no source recorded, so it is not shown how permission to contact them was obtained.";
            }
            if ($pct($bounced, $total) > 3) {
                $add('Outreach', 'high', "$label list: ".$pct($bounced, $total).'% of addresses bounce', 'The list has old or guessed addresses. High bounces damage the cvtara.in sending reputation for every mail.', $ev,
                    'Pause this list, remove addresses from unverified sources, and import only contacts with a known, permitted source.', 'Protects delivery of every outreach mail.', 'Bounce rate below 2% on the next 500 sends.');
            } elseif ($pct($clicked, $sent) < 1) {
                $add('Outreach', 'medium', "$label list: fewer than 1 in 100 mails get a click", 'The subject or first line does not speak to the reader\'s problem, the offer is weak, or the audience is not looking for this right now.', $ev,
                    'Prepare two different versions (subject, first line, one link) and test each on 200 contacts before sending more.', 'Better messages need fewer sends for the same sign-ups.', 'Clicks and sign-ups per 100 sent, per version.');
            }
        }
        // 9. Trust signals.
        $reviews = DB::table('feedback')->where('status', 'published')->count();
        if ($reviews < 5 && $m['signups'] >= 30) {
            $add('Trust', 'low', "Only $reviews published customer review(s)", 'New visitors have little proof that real people paid and were happy.', ["$reviews published reviews."], 'Ask recent paying customers for a review in their thank-you mail (no reward for positive reviews) and approve genuine ones.', 'More social proof on the plans section.', 'Published reviews and paid conversion, monthly.');
        }
        // 10. Segments: sign-up source.
        $seg = DB::table('users')->where('is_admin', false)->where('created_at', '>=', $from->copy()->utc())->whereNotNull('signup_source')->select('signup_source', DB::raw('count(*) as n'))->groupBy('signup_source')->pluck('n', 'signup_source');
        $segRows = [];
        foreach ($seg as $src => $n) {
            $b = Growth::sales()->whereIn('owner_id', DB::table('users')->where('signup_source', $src)->where('created_at', '>=', $from->copy()->utc())->select('id'))->distinct()->count('owner_id');
            $segRows[] = ['segment' => $src, 'signups' => (int) $n, 'buyers' => $b, 'rate' => $pct($b, $n)];
        }
        $weak = collect($segRows)->filter(fn ($r) => $r['signups'] >= 30 && $r['rate'] !== null && $r['rate'] < 2);
        foreach ($weak as $r) {
            $add('Segments', 'low', 'Sign-ups from '.$r['segment'].' rarely buy ('.$r['rate'].'%)', 'This channel brings people at an early stage (browsing or studying) rather than applying for jobs now.', [$r['signups'].' sign-ups, '.$r['buyers'].' buyers.'],
                'Keep the channel for sign-ups, but send these people the free ATS check and job-hunt tips first, and the plan offer only when they start applying.', 'Spend and effort go where buyers come from.', 'Buyers per channel, monthly.');
        }
        if (! $out) {
            $out[] = ['area' => 'Overall', 'sev' => 'info', 'finding' => 'No clear problem found in the last '.$days.' days', 'cause' => $m['signups'] < 20 ? 'There are too few sign-ups yet to judge any step reliably.' : 'Every step is within the usual range.', 'evidence' => [$m['signups'].' sign-ups, '.$m['purchases'].' paid orders.'],
                'action' => $m['signups'] < 20 ? 'Focus on traffic: SEO pages close to page 1, college outreach with consent, and referral sharing.' : 'Keep going; test one improvement at a time in Experiments.', 'impact' => '', 'measure' => 'Weekly report.', 'atStakePaise' => null];
        }
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
        usort($out, fn ($x, $y) => [$rank[$x['sev']], -($x['atStakePaise'] ?? 0)] <=> [$rank[$y['sev']], -($y['atStakePaise'] ?? 0)]);

        return ['days' => $days, 'findings' => $out, 'funnel' => $f, 'segments' => $segRows, 'biggestDrop' => collect($f['steps'])->filter(fn ($s) => $s['lost'] !== null && $s['key'] !== 'engaged')->sortByDesc('lost')->first(),
            'workedOutAt' => now()->toIso8601String()];
    }

    // ---------------------------------------------------------------- KPIs

    public static function kpis(int $days = 30): array
    {
        $now = now()->tz(Growth::TZ);
        $from = $now->copy()->subDays($days);
        $m = Growth::metrics($from, $now);
        $f = array_column(self::funnel($from, $now)['steps'], 'value', 'key');
        $upsellPaise = (int) Growth::sales()->where('purchased_at', '>=', $from->copy()->utc())->where(function ($w) use ($from) {
            $w->where('plan', 'like', 'addon-%')->orWhereIn('owner_id', Growth::sales()->where('purchased_at', '<', $from->copy()->utc())->select('owner_id'));
        })->sum('amount_paise');
        $plans = DB::table('growth_campaign_plans')->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()));
        $cost = (int) (clone $plans)->sum('cost_inr');
        $attr = 0;
        foreach ((clone $plans)->get() as $p) {
            $attr += self::campaignResult($p)['attributedNetPaise'] ?? 0;
        }
        $os = DB::table('outreach_sends')->where('kind', 'campaign')->where('status', 'sent')->where('created_at', '>=', $from->copy()->utc())->count();
        $oc = DB::table('outreach_contacts')->where('clicked_at', '>=', $from->copy()->utc())->count();
        $or = DB::table('outreach_contacts')->whereNotNull('user_id')->where('updated_at', '>=', $from->copy()->utc())->where('status', 'registered')->count();
        $complaints = DB::table('support_reports')->where('created_at', '>=', $from->copy()->utc())->whereIn('area', ['payment', 'download', 'resume', 'letter', 'ats'])->count();

        return ['days' => $days, 'registrations' => $m['signups'], 'activated' => $f['used'], 'freeToPaid' => $f['signups'] ? round($f['paid'] / $f['signups'] * 100, 1) : null,
            'transactions' => $m['purchases'], 'confirmedNetPaise' => $m['netPaise'], 'grossPaise' => $m['grossPaise'], 'aovPaise' => $m['purchases'] ? (int) round($m['grossPaise'] / $m['purchases']) : null,
            'repeatPurchases' => $m['repeatPurchases'], 'upsellPaise' => $upsellPaise, 'campaignCostInr' => $cost, 'attributedNetPaise' => $attr,
            'costPerNewCustomerInr' => $cost && $m['newCustomers'] ? (int) round($cost / $m['newCustomers']) : null,
            'outreachSent' => $os, 'outreachClickRate' => $os ? round($oc / $os * 100, 2) : null, 'outreachSignups' => $or,
            'refundRate' => $m['grossPaise'] ? round($m['refundedPaise'] / $m['grossPaise'] * 100, 1) : null, 'complaintsPer100Orders' => $m['purchases'] ? round($complaints / $m['purchases'] * 100, 1) : null,
            'adSpend' => null,
            'notTracked' => 'Ad spend is not connected. Email opens are not tracked. Attributed revenue is sales by people a campaign or landing page reached; it is shown apart from confirmed revenue and is not proof the campaign caused them. Cost per customer uses only costs you enter on campaigns.'];
    }

    // ---------------------------------------------------------------- campaign register

    /** What a registered campaign achieved: from its linked email campaign, outreach list or UTM tag / landing page. */
    public static function campaignResult(object $p): array
    {
        $from = $p->start_date ? Carbon::parse($p->start_date, Growth::TZ)->startOfDay()->utc() : Carbon::parse($p->created_at);
        $to = $p->end_date ? Carbon::parse($p->end_date, Growth::TZ)->endOfDay()->utc() : now();
        $reached = collect();
        $r = ['sent' => null, 'clicks' => null, 'signups' => null, 'buyers' => null, 'attributedNetPaise' => null, 'how' => []];
        if ($p->email_campaign_id) {
            $st = Campaigns::stats($p->email_campaign_id);
            $r['sent'] = $st['sent'];
            $reached = $reached->merge(DB::table('campaign_recipients')->where('campaign_id', $p->email_campaign_id)->whereNotNull('owner_id')->pluck('owner_id'));
            $r['how'][] = 'Email campaign recipients who paid within 14 days of sending.';
        }
        if ($p->outreach_list !== '') {
            $c = DB::table('outreach_contacts')->where('list', $p->outreach_list);
            $r['sent'] = ($r['sent'] ?? 0) + DB::table('outreach_sends')->where('kind', 'campaign')->where('status', 'sent')->whereBetween('created_at', [$from, $to])->whereIn('contact_id', (clone $c)->select('id'))->count();
            $r['clicks'] = (clone $c)->whereBetween('clicked_at', [$from, $to])->count();
            $reached = $reached->merge((clone $c)->whereNotNull('user_id')->pluck('user_id'));
            $r['how'][] = 'Outreach contacts who signed up and paid.';
        }
        $tagged = DB::table('users')->where('is_admin', false)->whereBetween('created_at', [$from, $to])->where(function ($q) use ($p) {
            $q->whereRaw('1 = 0');
            if ($p->utm !== '') {
                $q->orWhere('signup_campaign', $p->utm);
            }
            if ($p->landing !== '') {
                $q->orWhere('signup_landing', $p->landing);
            }
        })->pluck('id');
        if ($p->utm !== '' || $p->landing !== '') {
            $r['signups'] = $tagged->count();
            $reached = $reached->merge($tagged);
            $r['how'][] = 'Sign-ups whose first visit had '.trim(($p->utm !== '' ? 'utm_campaign='.$p->utm : '').($p->utm !== '' && $p->landing !== '' ? ' or ' : '').($p->landing !== '' ? 'landed on '.$p->landing : '')).' (visitors who allowed analytics).';
        }
        if ($reached->isNotEmpty() || $r['how']) {
            $end = $p->email_campaign_id && ! $p->end_date ? $from->copy()->addDays(14) : $to;
            $sales = Growth::sales()->whereIn('owner_id', $reached->unique()->values())->whereBetween('purchased_at', [$from, $end]);
            $r['buyers'] = (clone $sales)->distinct()->count('owner_id');
            $r['attributedNetPaise'] = (int) (clone $sales)->sum(DB::raw('amount_paise - refunded_paise'));
        }
        $r['costInr'] = (int) $p->cost_inr;
        $r['costPerBuyerInr'] = $r['buyers'] ? (int) round($p->cost_inr / $r['buyers']) : null;

        return $r;
    }

    public static function campaigns(): array
    {
        return DB::table('growth_campaign_plans')->orderByDesc('created_at')->limit(200)->get()->map(fn ($p) => (array) $p + ['result' => self::campaignResult($p), 'channelLabel' => self::CHANNELS[$p->channel] ?? $p->channel])->all();
    }

    public static function saveCampaign(array $v, ?string $id = null): string
    {
        $row = array_intersect_key($v, array_flip(['name', 'channel', 'status', 'audience', 'problem', 'offer', 'message', 'landing', 'utm', 'cta', 'budget_inr', 'cost_inr', 'expected', 'consent', 'email_campaign_id', 'outreach_list', 'start_date', 'end_date', 'result_note']));
        foreach (['audience', 'problem', 'offer', 'landing', 'utm', 'cta', 'expected', 'consent', 'outreach_list', 'result_note'] as $k) {
            $row[$k] = (string) ($row[$k] ?? '');
        }
        $row['updated_at'] = now();
        if ($id) {
            abort_unless(DB::table('growth_campaign_plans')->where('id', $id)->update($row), 404);

            return $id;
        }
        $id = (string) Str::uuid();
        DB::table('growth_campaign_plans')->insert($row + ['id' => $id, 'created_at' => now()]);

        return $id;
    }

    // ---------------------------------------------------------------- experiments

    /** Baseline = the same number of days just before the start; result = from the start to the end (or today). */
    public static function measure(object $e): array
    {
        $start = Carbon::parse($e->start_date, Growth::TZ)->startOfDay();
        $end = Carbon::parse($e->end_date, Growth::TZ)->endOfDay();
        $len = max(1, (int) $start->diffInDays($end->copy()->addSecond(), true));
        $base = ['from' => $start->copy()->subDays($len)->toDateString(), 'to' => $start->copy()->subDay()->toDateString(), 'value' => self::metric($e->metric, $start->copy()->subDays($len), $start)];
        $res = null;
        if ($start->isPast()) {
            $stop = $end->isFuture() ? now()->tz(Growth::TZ) : $end;
            $res = ['from' => $start->toDateString(), 'to' => $stop->toDateString(), 'value' => self::metric($e->metric, $start, $stop), 'complete' => ! $end->isFuture()];
            $res['change'] = $res['value'] !== null && $base['value'] ? round(($res['value'] - $base['value']) / $base['value'] * 100, 1) : null;
        }

        return ['baseline' => $base, 'result' => $res];
    }

    public static function refreshExperiments(): array
    {
        $n = 0;
        foreach (DB::table('growth_experiments')->where('status', '!=', 'finished')->orWhere('updated_at', '>=', now()->subDays(3))->get() as $e) {
            $m = self::measure($e);
            $status = Carbon::parse($e->start_date, Growth::TZ)->isFuture() ? 'planned' : (($m['result']['complete'] ?? false) ? 'finished' : 'running');
            DB::table('growth_experiments')->where('id', $e->id)->update(['baseline_json' => json_encode($m['baseline']), 'result_json' => json_encode($m['result']), 'status' => $status, 'updated_at' => now()]);
            $n++;
        }

        return ['summary' => "Measured $n experiment(s).", 'skipped' => $n === 0];
    }

    public static function experiments(): array
    {
        return DB::table('growth_experiments')->orderByDesc('start_date')->limit(200)->get()->map(fn ($e) => (array) $e + ['baseline' => json_decode((string) $e->baseline_json, true), 'result' => json_decode((string) $e->result_json, true), 'metricLabel' => self::METRICS[$e->metric] ?? $e->metric])->all();
    }

    public static function saveExperiment(array $v, ?string $id = null): string
    {
        $row = array_intersect_key($v, array_flip(['title', 'hypothesis', 'segment', 'metric', 'change', 'budget_inr', 'start_date', 'end_date', 'decision', 'notes']));
        foreach (['segment', 'change', 'decision', 'notes'] as $k) {
            $row[$k] = (string) ($row[$k] ?? '');
        }
        $row['updated_at'] = now();
        if ($id) {
            abort_unless(DB::table('growth_experiments')->where('id', $id)->update($row), 404);
        } else {
            $id = (string) Str::uuid();
            DB::table('growth_experiments')->insert($row + ['id' => $id, 'created_at' => now()]);
        }
        $e = DB::table('growth_experiments')->where('id', $id)->first();
        $m = self::measure($e);
        DB::table('growth_experiments')->where('id', $id)->update(['baseline_json' => json_encode($m['baseline']), 'result_json' => json_encode($m['result']),
            'status' => Carbon::parse($e->start_date, Growth::TZ)->isFuture() ? 'planned' : (($m['result']['complete'] ?? false) ? 'finished' : 'running')]);

        return $id;
    }

    // ---------------------------------------------------------------- daily run

    /** Once a day after 8 AM India time: diagnose, re-measure experiments. Recorded in the agent log. */
    /** Every cron minute: customer events every 30 minutes, journeys hourly. */
    public static function work(): void
    {
        if (! self::settings()['auto']) {
            return;
        }
        if (\Illuminate\Support\Facades\Cache::add('growth-events', 1, 1800)) {
            AgentLog::run('growth', 'customer-events', fn () => CustomerEvents::sync());
        }
        if (\Illuminate\Support\Facades\Cache::add('growth-journeys', 1, 3600)) {
            AgentLog::run('growth', 'journeys', fn () => Lifecycle::run());
        }
    }

    public static function run(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy()->tz(Growth::TZ);
        if (! self::settings()['auto'] || $now->hour < 8 || (Settings::get('growthOpsRun', [])['day'] ?? '') === $now->toDateString()) {
            return [];
        }
        Settings::put('growthOpsRun', ['day' => $now->toDateString(), 'at' => now()->toIso8601String()]);
        $d = AgentLog::run('growth', 'diagnose', function () {
            $d = self::diagnose();
            Settings::put('growthDiagnosis', ['at' => now()->toIso8601String(), 'findings' => $d['findings'], 'biggestDrop' => $d['biggestDrop']]);
            $tasks = AgentTasks::fromGrowthFindings($d['findings']); // 3.42.0

            return ['summary' => count($d['findings']).' finding(s), '.$tasks.' new task(s); top: '.($d['findings'][0]['finding'] ?? 'none').'.'];
        });
        $e = AgentLog::run('growth', 'experiments', fn () => self::refreshExperiments());

        return ['diagnose' => $d, 'experiments' => $e];
    }
}
