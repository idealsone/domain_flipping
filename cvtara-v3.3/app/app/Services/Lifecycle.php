<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The growth agent's customer journeys, checkout recovery and referral activation.
 *
 * Journeys add behaviour-triggered mails to the ones MailAutomation already sends (welcome, payment reminders, renewal reminders,
 * nurture, referral invite, plan ended, monthly guide). Each journey mail:
 * - is a marketing mail: only to people who opted in, never to suppressed addresses, with the unsubscribe footer;
 * - ships switched off: the owner reads the template in Emails and switches it on (that is the approval);
 * - is sent at most once per person, at most one marketing mail per person in 3 days, and at most the daily cap per journey;
 * - is dropped before sending if the person already did what it asks or bought something meanwhile.
 * Nothing here marks a payment as paid, issues refunds or grants anything: those stay with the gateway records and the owner.
 */
class Lifecycle
{
    public const JOURNEYS = [
        'activation_nudge' => ['template' => 'activation-1', 'label' => 'Getting started (signed up 1 to 7 days ago, no resume yet)', 'goal' => 'resume_created'],
        'ats_nudge' => ['template' => 'ats-1', 'label' => 'First ATS check (has a resume, never ran a check)', 'goal' => 'ats_check'],
        'pricing_help' => ['template' => 'pricing-help', 'label' => 'Plan questions (looked at checkout, did not start paying)', 'goal' => 'payment_paid'],
    ];

    public const DEFAULTS = ['dailyCap' => 100];

    public static function settings(): array
    {
        $s = (array) Settings::get('lifecycle', []);

        return ['dailyCap' => max(0, min(1000, (int) ($s['dailyCap'] ?? self::DEFAULTS['dailyCap'])))];
    }

    /** Autonomy levels the owner set (1 monitor … 4 approved execution). Level 5 actions are never automated. */
    public static function level(string $agent): int
    {
        $l = (array) Settings::get('agentLevels', []);

        return max(1, min(4, (int) ($l[$agent] ?? 3)));
    }

    private static function enabled(string $templateId): bool
    {
        $pick = fn ($list) => collect($list)->first(fn ($t) => ($t['id'] ?? '') === $templateId);
        $t = $pick(Settings::get('emails', [])) ?: $pick(Settings::defaults()['emails']);

        return (bool) ($t['enabled'] ?? false);
    }

    private static function recentMarketing(int $uid, int $days): bool
    {
        return DB::table('mail_outbox')->where('owner_id', $uid)->whereIn('trigger', EmailRenderer::MARKETING)->where('created_at', '>=', now()->subDays($days))->exists();
    }

    /** Queues journey mails for people who qualify now. Runs with the growth agent (hourly). */
    public static function run(): array
    {
        if (! GrowthOps::settings()['auto'] || self::level('growth') < 3) {
            return ['skipped' => true, 'summary' => 'Journeys wait: the growth agent is paused or set below level 3.'];
        }
        $cap = self::settings()['dailyCap'];
        $queued = [];
        foreach (self::JOURNEYS as $trigger => $j) {
            $queued[$trigger] = 0;
            if (! self::enabled($j['template'])) {
                continue;
            }
            $today = DB::table('mail_outbox')->where('trigger', $trigger)->where('created_at', '>=', now()->subDay())->count();
            $room = $cap - $today;
            if ($room <= 0) {
                continue;
            }
            $done = DB::table('mail_outbox')->where('trigger', $trigger)->select('owner_id');
            $q = CustomerEvents::reachable(match ($trigger) {
                'activation_nudge' => CustomerEvents::segment('inactive')->whereBetween('created_at', [now()->subDays(7), now()->subDay()]),
                'ats_nudge' => CustomerEvents::segment('resume_no_ats')->where('created_at', '>=', now()->subDays(60))->whereIn('id', DB::table('resume_drafts')->where('created_at', '<=', now()->subDays(2))->select('owner_id')),
                'pricing_help' => DB::table('users')->where('is_admin', false)->where('blocked', false)
                    ->whereIn('id', DB::table('customer_events')->where('type', 'pricing_view')->whereBetween('occurred_at', [now()->subDays(3), now()->subHours(2)])->select('owner_id'))
                    ->whereNotIn('id', DB::table('purchases')->where('created_at', '>=', now()->subDays(3))->select('owner_id'))
                    ->whereNotIn('id', DB::table('purchases')->whereNotNull('purchased_at')->where('provider', '!=', 'referral_reward')->select('owner_id')),
            })->whereNotIn('id', $trigger === 'pricing_help' ? DB::table('mail_outbox')->where('trigger', $trigger)->where('created_at', '>=', now()->subDays(60))->select('owner_id') : $done);
            foreach ($q->orderBy('id')->limit($room * 2)->pluck('id') as $id) {
                if ($queued[$trigger] >= $room) {
                    break;
                }
                if (self::recentMarketing((int) $id, 3)) {
                    continue;
                }
                $u = User::find($id);
                if ($u && MailAutomation::queue($u, $trigger, $trigger === 'pricing_help' ? 'p'.now()->format('Ym') : 'once', [], false, $j['template'])) {
                    $queued[$trigger]++;
                }
            }
        }
        $n = array_sum($queued);

        return ['summary' => $n ? "Queued $n journey mail(s): ".implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys(array_filter($queued)), array_filter($queued))).'.' : 'No one new qualified for a switched-on journey.', 'queued' => $queued];
    }

    /** Checked just before a journey mail goes out: false when the person already did what it asks, or bought. */
    public static function stillEligible(object $j): bool
    {
        if (! isset(self::JOURNEYS[$j->trigger]) || ! $j->owner_id) {
            return true;
        }
        $since = (string) $j->created_at;
        if (DB::table('purchases')->where('owner_id', $j->owner_id)->whereNotNull('purchased_at')->where('provider', '!=', 'referral_reward')->exists() && $j->trigger !== 'ats_nudge') {
            return false;
        }

        return match ($j->trigger) {
            'activation_nudge' => ! DB::table('resume_drafts')->where('owner_id', $j->owner_id)->exists() && ! DB::table('ats_scans')->where('owner_id', $j->owner_id)->exists(),
            'ats_nudge' => ! DB::table('ats_scans')->where('owner_id', $j->owner_id)->exists(),
            'pricing_help' => ! DB::table('purchases')->where('owner_id', $j->owner_id)->where('created_at', '>', $since)->exists(),
            default => true,
        };
    }

    /** What each journey did: queued, sent, stopped, and what the people who got it did next. */
    public static function report(int $days = 30): array
    {
        $from = now()->subDays($days);
        $out = [];
        foreach (self::JOURNEYS as $trigger => $j) {
            $rows = DB::table('mail_outbox')->where('trigger', $trigger)->where('created_at', '>=', $from)->get(['owner_id', 'status', 'sent_at']);
            $sent = $rows->where('status', 'sent');
            $goal = 0;
            $buyers = collect();
            foreach ($sent as $m) {
                $at = (string) $m->sent_at;
                $goal += DB::table('customer_events')->where('owner_id', $m->owner_id)->where('type', $j['goal'])->where('occurred_at', '>', $at)->where('occurred_at', '<=', date('Y-m-d H:i:s', strtotime($at) + 7 * 86400))->exists() ? 1 : 0;
                if (Growth::sales()->where('owner_id', $m->owner_id)->where('purchased_at', '>', $at)->where('purchased_at', '<=', date('Y-m-d H:i:s', strtotime($at) + 14 * 86400))->exists()) {
                    $buyers->push($m->owner_id);
                }
            }
            $sales = $buyers->isEmpty() ? collect() : Growth::sales()->whereIn('owner_id', $buyers->unique())->where('purchased_at', '>=', $from)->get(['amount_paise', 'refunded_paise']);
            $out[] = ['trigger' => $trigger, 'template' => $j['template'], 'label' => $j['label'], 'on' => self::enabled($j['template']), 'queued' => $rows->whereIn('status', ['queued', 'failed', 'sending'])->count(), 'sent' => $sent->count(),
                'stopped' => $rows->where('status', 'cancelled')->count(), 'didGoal' => $goal, 'goalLabel' => CustomerEvents::TYPES[$j['goal']] ?? $j['goal'], 'buyers' => $buyers->unique()->count(), 'attributedNetPaise' => (int) ($sales->sum('amount_paise') - $sales->sum('refunded_paise'))];
        }
        // The existing automatic mails, so the owner sees the whole lifecycle in one place.
        $existing = [];
        foreach (['abandoned_purchase' => 'Payment not finished (two reminders)', 'access_expiring_7' => 'Plan ends in 7 days', 'access_expiring_4' => 'Plan ends in 4 days', 'access_expiring_1' => 'Plan ends tomorrow', 'nurture' => 'Plan tips for free users (12 over a year)',
            'referral_invite' => 'Referral invite after Job Hunt or Pro', 'plan_ended' => 'Plan ended two weeks ago', 'monthly_digest' => 'Monthly career guide', 'purchase_verified' => 'Payment receipt and onboarding'] as $t => $label) {
            $r = DB::table('mail_outbox')->where('trigger', $t)->where('created_at', '>=', $from)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
            $existing[] = ['trigger' => $t, 'label' => $label, 'sent' => (int) ($r['sent'] ?? 0), 'stopped' => (int) ($r['cancelled'] ?? 0), 'waiting' => (int) (($r['queued'] ?? 0) + ($r['failed'] ?? 0))];
        }

        return ['days' => $days, 'journeys' => $out, 'existing' => $existing, 'settings' => self::settings(), 'level' => self::level('growth'),
            'note' => 'Journey results are what recipients did within 7 days (goal) and 14 days (purchase) of the mail. That is attribution, not proof the mail caused it. Email opens are not tracked.'];
    }

    // ---------------------------------------------------------------- checkout and payment recovery

    public static function checkout(int $days = 30): array
    {
        $from = now()->subDays($days);
        $orders = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->where('p.provider', '!=', 'referral_reward')->where('p.amount_paise', '>', 0)->where('p.created_at', '>=', $from)
            ->when(! config('app.preview'), fn ($q) => $q->where('p.provider', '!=', 'demo'))
            ->orderByDesc('p.created_at')->get(['p.id', 'p.owner_id', 'p.plan', 'p.amount_paise', 'p.refunded_paise', 'p.status', 'p.provider', 'p.created_at', 'p.purchased_at', 'p.touch_source', 'u.email']);
        $mails = DB::table('mail_outbox')->where('trigger', 'abandoned_purchase')->where('created_at', '>=', $from)->get(['owner_id', 'status', 'sent_at', 'created_at'])->groupBy('owner_id');
        $plans = Settings::plans();
        $rows = [];
        $k = ['started' => 0, 'paidDirect' => 0, 'recovered' => 0, 'recoveredPaise' => 0, 'pending' => 0, 'abandoned' => 0, 'failed' => 0, 'refunded' => 0, 'refundedPaise' => 0, 'duplicates' => 0, 'remindersSent' => 0];
        $paidByOwner = $orders->filter(fn ($o) => $o->purchased_at)->groupBy('owner_id');
        foreach ($orders as $o) {
            $k['started']++;
            $m = ($mails[$o->owner_id] ?? collect())->filter(fn ($x) => $x->created_at >= $o->created_at);
            $sent = $m->where('status', 'sent');
            $firstSent = $sent->min('sent_at');
            $outcome = match (true) {
                in_array($o->status, ['paid', 'captured', 'partially_refunded'], true) && $firstSent && $o->purchased_at > $firstSent => 'recovered',
                in_array($o->status, ['paid', 'captured', 'partially_refunded'], true) => 'paid',
                $o->status === 'refunded' => 'refunded',
                $o->status === 'disputed' => 'disputed',
                $o->status === 'failed' => 'failed',
                strtotime((string) $o->created_at) > time() - 3600 => 'pending',
                default => 'abandoned',
            };
            // A later order by the same person that was paid after a reminder recovers this one too.
            if (in_array($outcome, ['abandoned', 'failed'], true) && $firstSent && ($paidByOwner[$o->owner_id] ?? collect())->contains(fn ($x) => $x->purchased_at > $firstSent && $x->created_at > $o->created_at)) {
                $outcome = 'recovered_later';
            }
            if ($o->refunded_paise > 0) {
                $k['refunded']++;
                $k['refundedPaise'] += $o->refunded_paise;
            }
            match ($outcome) {
                'paid' => $k['paidDirect']++,
                'recovered' => [$k['recovered']++, $k['recoveredPaise'] += $o->amount_paise - $o->refunded_paise],
                'pending' => $k['pending']++,
                'failed' => $k['failed']++,
                'abandoned' => $k['abandoned']++,
                default => null,
            };
            $k['remindersSent'] += $sent->count();
            $rows[] = ['id' => $o->id, 'at' => $o->created_at, 'email' => $o->email, 'plan' => $plans[$o->plan]['name'] ?? $o->plan, 'amountPaise' => $o->amount_paise, 'gateway' => $o->provider, 'status' => $o->status, 'outcome' => $outcome, 'reminders' => $sent->count(), 'waiting' => $m->whereIn('status', ['queued', 'failed'])->count(), 'source' => $o->touch_source];
        }
        // Two confirmed payments for the same package by the same person within 30 minutes: probably paid twice.
        $dups = [];
        foreach ($paidByOwner as $owner => $g) {
            $g = $g->filter(fn ($x) => in_array($x->status, ['paid', 'captured', 'partially_refunded'], true))->sortBy('purchased_at')->values();
            for ($i = 1; $i < $g->count(); $i++) {
                if ($g[$i]->plan === $g[$i - 1]->plan && strtotime((string) $g[$i]->purchased_at) - strtotime((string) $g[$i - 1]->purchased_at) <= 1800) {
                    $dups[] = ['email' => $g[$i]->email, 'plan' => $plans[$g[$i]->plan]['name'] ?? $g[$i]->plan, 'orders' => [$g[$i - 1]->id, $g[$i]->id], 'at' => $g[$i]->purchased_at];
                }
            }
        }
        $k['duplicates'] = count($dups);
        $k['recoveryRate'] = ($k['recovered'] + $k['abandoned'] + $k['failed']) ? round($k['recovered'] / ($k['recovered'] + $k['abandoned'] + $k['failed']) * 100, 1) : null;

        return ['days' => $days, 'kpis' => $k, 'rows' => $rows, 'duplicates' => $dups,
            'note' => 'Statuses come from the gateways\' signed confirmations (webhooks) and the order ledger only. A failed or unfinished payment is never treated as paid, and a refund or extra access is only ever given by you. Possible double payments are listed for you to check with the gateway.'];
    }

    // ---------------------------------------------------------------- referral activation

    public static function referrals(int $days = 30): array
    {
        $from = now()->subDays($days);
        $on = Wallet::enabled();
        $min = (int) (Wallet::rules()['minQualifyingPaise'] ?? 0);
        $services = array_keys(array_filter(Settings::plans(), fn ($p) => ($p['type'] ?? 'plan') !== 'plan'));
        $qualifying = DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('provider', '!=', 'referral_reward')->where('plan', 'not like', 'addon-%')->whereNotIn('plan', $services ?: [''])
            ->where('refunded_paise', 0)->where('amount_paise', '>=', $min)->where('purchased_at', '<=', now()->subDays(MailAutomation::REFERRAL_INVITE_DAYS));
        $eligible = (clone $qualifying)->distinct()->pluck('owner_id');
        $invited = DB::table('mail_outbox')->where('trigger', 'referral_invite')->where('status', 'sent')->whereIn('owner_id', $eligible)->distinct()->count('owner_id');
        $consented = DB::table('profiles')->where('marketing_consent', true)->whereIn('owner_id', $eligible)->pluck('owner_id');
        $referred = DB::table('users')->whereNotNull('referred_by')->where('created_at', '>=', $from);
        $comm = DB::table('referral_commissions')->where('created_at', '>=', $from);
        $paidRef = (clone $comm)->whereColumn('reversed_paise', '<', 'amount_paise')->count();
        $top = DB::table('users as r')->join('users as f', 'f.referred_by', '=', 'r.id')->where('f.created_at', '>=', $from)->groupBy('r.id', 'r.email')->select('r.id', 'r.email', DB::raw('count(*) as signups'))->orderByDesc('signups')->limit(10)->get()
            ->map(fn ($x) => ['email' => $x->email, 'signups' => (int) $x->signups, 'paid' => DB::table('referral_commissions')->where('referrer_id', $x->id)->where('created_at', '>=', $from)->whereColumn('reversed_paise', '<', 'amount_paise')->count(),
                'commissionPaise' => (int) DB::table('referral_commissions')->where('referrer_id', $x->id)->where('created_at', '>=', $from)->sum(DB::raw('amount_paise - reversed_paise'))])->all();
        $flags = [];
        foreach (DB::table('users')->whereNotNull('referred_by')->where('created_at', '>=', $from)->get(['referred_by', 'created_at', 'email_verified_at'])->groupBy('referred_by') as $rid => $g) {
            $email = DB::table('users')->where('id', $rid)->value('email');
            $perDay = $g->groupBy(fn ($u) => substr((string) $u->created_at, 0, 10))->map->count()->max();
            if ($perDay >= 5) {
                $flags[] = ['email' => $email, 'why' => "$perDay referred sign-ups on one day"];
            }
            $unverified = $g->whereNull('email_verified_at')->count();
            if ($g->count() >= 5 && $unverified / $g->count() >= 0.8) {
                $flags[] = ['email' => $email, 'why' => "$unverified of {$g->count()} referred accounts never verified their email"];
            }
        }
        foreach (DB::table('referral_commissions')->where('created_at', '>=', now()->subDays(90))->where('reversed_paise', '>', 0)->select('referrer_id', DB::raw('count(*) as n'))->groupBy('referrer_id')->having('n', '>=', 2)->get() as $x) {
            $flags[] = ['email' => DB::table('users')->where('id', $x->referrer_id)->value('email'), 'why' => "$x->n referred orders refunded or charged back in 90 days"];
        }

        return ['days' => $days, 'on' => $on, 'eligible' => $eligible->count(), 'invited' => $invited, 'notInvitedConsented' => $consented->diff(DB::table('mail_outbox')->where('trigger', 'referral_invite')->pluck('owner_id'))->count(),
            'clicks' => DB::table('referral_clicks')->where('day', '>=', $from->toDateString())->count(), 'signups' => (clone $referred)->count(), 'verified' => (clone $referred)->whereNotNull('email_verified_at')->count(),
            'paidReferrals' => $paidRef, 'commissionPaise' => (int) (clone $comm)->sum('amount_paise'), 'reversedPaise' => (int) (clone $comm)->sum('reversed_paise'),
            'referredRevenuePaise' => (int) Growth::sales()->whereIn('id', (clone $comm)->select('purchase_id'))->sum(DB::raw('amount_paise - refunded_paise')),
            'signupToPaid' => (clone $referred)->count() ? round($paidRef / (clone $referred)->count() * 100, 1) : null, 'top' => $top, 'flags' => $flags,
            'note' => 'A referred sign-up is not revenue: only a paid order that passes the refund window counts, and refunds or chargebacks reverse the bonus. Flags are for you to review; nothing is blocked automatically.'];
    }
}
