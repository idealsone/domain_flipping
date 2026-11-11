<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PartnerHub part 2 (3.54.1, PartnerHub V2 §4-§19): campaigns with seats and targets, agreement versions the partner
 * accepts in the portal, student invite lists, payout requests, monthly ranking and reward (gift hamper) records.
 *
 * Rules kept from the documents:
 *  - a seat is used only by a verified sign-up through the campaign link; clicks and failed sign-ups use none;
 *  - a campaign takes sign-ups only between its dates, while seats remain and after the agreement is accepted;
 *  - accepted agreement text is never changed: a change is a new version that needs fresh acceptance;
 *  - an uploaded student list creates no accounts and sends no email: it makes personal links the partner shares;
 *  - payouts: ₹5,000 available at least, the owner pays and records the reference; rewards never replace commission.
 */
class PartnerOps
{
    public const MIN_PAYOUT_PAISE = 500000;

    public const DURATIONS = [15, 30, 45, 60, 90];

    public const MAX_INVITES = 1000;

    // ---------------------------------------------------------------- campaigns and seats

    public static function seatsUsed(string $code): int
    {
        // When email verification is switched off, every completed sign-up counts.
        return DB::table('users')->where('acq_link', $code)->when(EmailVerification::enabled(), fn ($q) => $q->whereNotNull('email_verified_at'))->count();
    }

    /** Allocated, invited, registered, verified, paid, refunded and unused seats for one campaign link. */
    public static function campaign(object $l): array
    {
        $users = DB::table('users')->where('acq_link', $l->code);
        $paid = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->where('u.acq_link', $l->code)->where('p.is_live', true)->where('p.amount_paise', '>', 0);
        $verified = self::seatsUsed($l->code);

        return ['allocated' => $l->seats !== null ? (int) $l->seats : null, 'invited' => DB::table('partner_invites')->where('link_id', $l->id)->count(), 'registered' => (clone $users)->count(),
            'verified' => $verified, 'paid' => (clone $paid)->whereIn('p.status', ['paid', 'partially_refunded'])->distinct('p.owner_id')->count('p.owner_id'),
            'refunded' => (clone $paid)->where('p.status', 'refunded')->distinct('p.owner_id')->count('p.owner_id'),
            'unused' => $l->seats !== null ? max(0, (int) $l->seats - $verified) : null, 'target' => $l->target_paid !== null ? (int) $l->target_paid : null,
            'startsOn' => $l->starts_on, 'endsOn' => $l->ends_on, 'open' => (bool) $l->active && ($l->seats === null || $verified < (int) $l->seats) && (! $l->ends_on || $l->ends_on >= today()->toDateString()) && (! $l->starts_on || $l->starts_on <= today()->toDateString())];
    }

    public static function saveCampaign(User $admin, int $linkId, array $v): void
    {
        $l = DB::table('partner_links')->where('id', $linkId)->first();
        abort_unless($l, 404);
        $row = ['updated_at' => now()];
        if (array_key_exists('seats', $v)) {
            $row['seats'] = $v['seats'] === null || $v['seats'] === '' ? null : (int) $v['seats'];
        }
        if (array_key_exists('target', $v)) {
            $row['target_paid'] = $v['target'] === null || $v['target'] === '' ? null : (int) $v['target'];
        }
        if (! empty($v['startsOn'])) {
            $row['starts_on'] = $v['startsOn'];
        }
        if (! empty($v['days'])) {
            $start = $row['starts_on'] ?? $l->starts_on ?? today()->toDateString();
            $row['starts_on'] = $start;
            $row['ends_on'] = date('Y-m-d', strtotime($start.' +'.((int) $v['days'] - 1).' days'));
        }
        if (! empty($v['extendDays']) && ($l->ends_on || ! empty($row['ends_on']))) {
            $row['ends_on'] = date('Y-m-d', strtotime(($row['ends_on'] ?? $l->ends_on).' +'.(int) $v['extendDays'].' days'));
        }
        if (isset($v['action'])) {
            match ($v['action']) {
                'pause' => $row['active'] = false,
                'resume' => $row['active'] = true,
                'close' => $row += ['active' => false, 'ends_on' => min((string) ($l->ends_on ?: today()->toDateString()), today()->toDateString())],
                default => null,
            };
        }
        DB::table('partner_links')->where('id', $linkId)->update($row);
        Settings::audit($admin->id, 'partner_campaign_saved', $l->partner_id.':'.$l->code.' '.json_encode(array_diff_key($row, ['updated_at' => 1])));
    }

    // ---------------------------------------------------------------- agreements

    public static function draftAgreement(object $p): string
    {
        $plan = rtrim(rtrim(number_format($p->plan_bps / 100, 2), '0'), '.');
        $addon = rtrim(rtrim(number_format($p->addon_bps / 100, 2), '0'), '.');
        $min = number_format(self::MIN_PAYOUT_PAISE / 100);

        return "Partnership agreement between CVTara Career Solutions (\"CVTara\") and {$p->name} (\"the Partner\").\n\n"
            ."1. Service. CVTara provides its online resume, ATS check, cover letter and interview practice service to students and job seekers who sign up through the Partner's campaign links. Each person signs up and agrees to CVTara's terms themselves.\n\n"
            ."2. Campaigns. CVTara sets up campaign links with dates, seats and targets agreed with the Partner. A seat is used only by a verified sign-up. Targets are planning goals; missing one does not end this agreement or remove commission already earned.\n\n"
            ."3. Commission. {$plan}% of the net amount CVTara collects for plans and {$addon}% for add-ons bought by people who signed up through the Partner's links, excluding GST. The ₹99 Quick Download earns no commission. One order never earns both partner commission and a customer referral reward.\n\n"
            ."4. Holding period and refunds. Each commission is held for ".Refunds::DAYS." days (the customer refund window). A refund or chargeback cancels the commission for that order.\n\n"
            ."5. Payout. The Partner may request payout once at least ₹{$min} is available. CVTara pays by bank transfer against the Partner's invoice, subject to applicable tax deduction and KYC.\n\n"
            ."6. Student data. The Partner sees totals only (sign-ups, buyers, revenue, commission), never a student's resume, documents or contact details. If the Partner shares a list of students, the Partner confirms it has the authority and has told the students; CVTara uses the list only to make personal sign-up links and sends no marketing to it.\n\n"
            ."7. Conduct. Neither party promises jobs, interviews or salaries. The Partner will not describe CVTara's service inaccurately or use CVTara's name or logo beyond what CVTara approves.\n\n"
            ."8. Term and ending. This agreement runs from the start date until either party ends it with 30 days' written notice. Commission earned before the end stays payable. On ending, campaign links stop and CVTara deletes any student list the Partner shared.\n\n"
            ."9. Changes. A change to these terms is a new version that the Partner must accept again. Accepted versions are kept unchanged.";
    }

    public static function sendAgreement(User $admin, int $partnerId, ?string $body, ?string $title): array
    {
        $p = DB::table('partners')->where('id', $partnerId)->first();
        abort_unless($p, 404);
        $body = trim((string) ($body ?: self::draftAgreement($p)));
        abort_if(mb_strlen($body) < 200, 422, 'The agreement text is too short.');
        $ver = (int) DB::table('partner_agreements')->where('partner_id', $partnerId)->max('version') + 1;
        // Earlier versions that were sent but not accepted are replaced; an accepted one stays until the new one is accepted.
        DB::table('partner_agreements')->where('partner_id', $partnerId)->where('status', 'sent')->update(['status' => 'superseded', 'updated_at' => now()]);
        $id = DB::table('partner_agreements')->insertGetId(['partner_id' => $partnerId, 'version' => $ver, 'title' => mb_substr($title ?: 'CVTara partnership agreement', 0, 160), 'body' => $body,
            'body_hash' => hash('sha256', $body), 'status' => 'sent', 'sent_at' => now(), 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('partner_members')->where('partner_id', $partnerId)->pluck('user_id') as $uid) {
            self::notify((int) $uid, 'partner_agreement', 'Partnership agreement to review', 'Version '.$ver.' of your CVTara partnership agreement is ready. Open the Partner portal to read and accept it.', 'agreement:'.$id);
        }
        Settings::audit($admin->id, 'partner_agreement_sent', $partnerId.' v'.$ver);

        return ['id' => $id, 'version' => $ver];
    }

    /** Partner manager accepts the version on screen. The text hash shown must match the stored one. */
    public static function acceptAgreement(User $u, int $id, string $hash, string $name, Request $r): void
    {
        $m = Partners::membership($u);
        abort_unless($m && $m->role === 'manager', 403, 'Only a partner manager can accept the agreement.');
        $a = DB::table('partner_agreements')->where('id', $id)->where('partner_id', $m->partner_id)->first();
        abort_unless($a, 404);
        abort_unless($a->status === 'sent', 409, 'This version is not waiting for acceptance.');
        abort_unless(hash_equals($a->body_hash, $hash), 409, 'The agreement changed while you were reading it. Please reload and read it again.');
        DB::transaction(function () use ($a, $u, $name, $r) {
            DB::table('partner_agreements')->where('partner_id', $a->partner_id)->where('status', 'accepted')->update(['status' => 'superseded', 'updated_at' => now()]);
            DB::table('partner_agreements')->where('id', $a->id)->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by' => $u->id, 'accepted_name' => mb_substr(trim($name), 0, 120), 'accepted_ip' => $r->ip(), 'accept_method' => 'portal_checkbox_typed_name', 'updated_at' => now()]);
        });
        Settings::audit($u->id, 'partner_agreement_accepted', $a->partner_id.' v'.$a->version.' '.$a->body_hash);
    }

    public static function agreements(int $partnerId): array
    {
        return DB::table('partner_agreements')->where('partner_id', $partnerId)->orderByDesc('version')
            ->get(['id', 'version', 'title', 'body', 'body_hash', 'status', 'sent_at', 'accepted_at', 'accepted_name', 'accept_method'])->map(fn ($a) => (array) $a)->all();
    }

    // ---------------------------------------------------------------- student invites

    /** Rows of name/email from CSV, pasted text or the first sheet of an .xlsx file. Makes personal links; sends nothing. */
    public static function invite(User $u, int $partnerId, int $linkId, string $text, bool $authority): array
    {
        abort_unless($authority, 422, 'Please confirm that you may share these students\' details and have told them.');
        $l = DB::table('partner_links')->where('id', $linkId)->where('partner_id', $partnerId)->first();
        abort_unless($l, 404, 'Choose one of your campaign links.');
        $rows = [];
        $bad = 0;
        $dup = 0;
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $cells = array_map(fn ($c) => trim($c, " \t\"'"), preg_split('/[,\t;]/', $line));
            $email = null;
            $name = null;
            foreach ($cells as $c) {
                if (! $email && filter_var($c, FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower($c);
                } elseif (! $name && $c !== '' && ! str_contains($c, '@') && ! preg_match('/^(name|email|e-mail|student)/i', $c)) {
                    $name = mb_substr($c, 0, 120);
                }
            }
            if ($email) {
                if (array_key_exists($email, $rows)) {
                    $dup++;
                }
                $rows[$email] ??= $name;
            } elseif (trim($line) !== '' && ! preg_match('/e-?mail/i', $line)) {
                $bad++;
            }
        }
        abort_if(! $rows, 422, 'No email addresses were found. Use one student per line: name, email.');
        abort_if(count($rows) > self::MAX_INVITES, 422, 'Up to '.self::MAX_INVITES.' students per upload, please.');
        $c = self::campaign($l);
        $already = DB::table('partner_invites')->where('partner_id', $partnerId)->whereIn('email', array_keys($rows))->pluck('email')->flip();
        $fresh = array_diff_key($rows, $already->all());
        if ($c['allocated'] !== null) {
            $left = max(0, $c['allocated'] - $c['invited']);
            abort_if(count($fresh) > $left, 422, 'This campaign has '.$left.' seats left to invite. Ask CVTara for more seats or upload fewer students.');
        }
        $existing = DB::table('users')->whereIn('email', array_keys($fresh))->pluck('id', 'email');
        $out = [];
        foreach ($fresh as $email => $name) {
            $tok = Str::random(32);
            $has = isset($existing[$email]);
            DB::table('partner_invites')->insert(['partner_id' => $partnerId, 'link_id' => $l->id, 'email' => $email, 'name' => $name, 'token' => $tok, 'status' => $has ? 'has_account' : 'invited', 'uploaded_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            $out[] = ['name' => $name, 'email' => $email, 'link' => $has ? '' : url('/p/'.$l->code).'?i='.$tok, 'note' => $has ? 'Already has a CVTara account, so it is not counted for any partner.' : ''];
        }
        Settings::audit($u->id, 'partner_students_invited', $partnerId.':'.count($out));

        return ['added' => count($out), 'duplicates' => $dup + count($rows) - count($fresh), 'unreadable' => $bad, 'hasAccount' => $existing->count(), 'invites' => $out];
    }

    /** After a partner-attributed sign-up: the matching invite (by personal link or by email) is marked registered. */
    public static function inviteRegistered($user, int $partnerId, Request $r): void
    {
        $tok = $r->hasSession() ? ((array) $r->session()->get('partner', []))['invite'] ?? null : null;
        $q = DB::table('partner_invites')->where('partner_id', $partnerId)->whereNull('user_id');
        $q->where(fn ($w) => $w->where('email', strtolower((string) $user->email))->when($tok, fn ($x) => $x->orWhere('token', $tok)));
        $q->limit(1)->update(['status' => 'registered', 'user_id' => $user->id, 'updated_at' => now()]);
    }

    // ---------------------------------------------------------------- payouts

    public static function requestPayout(User $u): array
    {
        $m = Partners::membership($u);
        abort_unless($m && $m->role === 'manager', 403, 'Only a partner manager can request a payout.');
        abort_if(DB::table('partner_payouts')->where('partner_id', $m->partner_id)->where('status', 'requested')->exists(), 409, 'A payout request is already with CVTara.');
        $c = DB::table('partner_commissions')->where('partner_id', $m->partner_id)->where('status', 'available')->get(['id', 'amount_paise']);
        $sum = (int) $c->sum('amount_paise');
        abort_if($sum < self::MIN_PAYOUT_PAISE, 422, 'Payout can be requested once ₹'.number_format(self::MIN_PAYOUT_PAISE / 100).' is ready to pay. You have ₹'.number_format($sum / 100).'.');
        $id = DB::table('partner_payouts')->insertGetId(['partner_id' => $m->partner_id, 'amount_paise' => $sum, 'commission_ids' => json_encode($c->pluck('id')->all()), 'status' => 'requested', 'requested_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        $owner = (int) DB::table('users')->where('is_admin', true)->orderBy('id')->value('id');
        if ($owner) {
            self::notify($owner, 'partner_payout', 'Partner payout requested', $m->name.' asked for ₹'.number_format($sum / 100).'. Open B2B Partners > Payouts.', 'partner-payout:'.$id);
        }
        Settings::audit($u->id, 'partner_payout_requested', $m->partner_id.':'.$sum);

        return ['id' => $id, 'amountPaise' => $sum];
    }

    public static function decidePayout(User $admin, int $id, string $decision, string $reference, string $note): void
    {
        $p = DB::table('partner_payouts')->where('id', $id)->where('status', 'requested')->first();
        abort_unless($p, 409, 'This payout was already decided.');
        DB::transaction(function () use ($admin, $p, $decision, $reference, $note) {
            if ($decision === 'paid') {
                Partners::markPaid($admin, json_decode($p->commission_ids, true) ?: [], $reference);
            }
            DB::table('partner_payouts')->where('id', $p->id)->update(['status' => $decision, 'reference' => $reference ?: null, 'note' => $note ?: null, 'decided_by' => $admin->id, 'decided_at' => now(), 'updated_at' => now()]);
        });
        foreach (DB::table('partner_members')->where('partner_id', $p->partner_id)->where('role', 'manager')->pluck('user_id') as $uid) {
            self::notify((int) $uid, 'partner_payout', $decision === 'paid' ? 'Payout sent' : 'Payout request declined', $decision === 'paid' ? '₹'.number_format($p->amount_paise / 100).' was paid. Reference: '.$reference.'.' : 'Your payout request was declined: '.($note ?: 'please contact CVTara.'), 'partner-payout-'.$decision.':'.$p->id);
        }
    }

    // ---------------------------------------------------------------- monthly ranking

    /**
     * Ranks active partners for a month by a published score (weights in settings): verified sign-ups 40, paying
     * customers 40, conversion 20, each scaled to the best partner that month. Shown to the owner and to each partner
     * for itself only. Not a public leaderboard; fraud and suspension are handled separately.
     */
    public static function rank(?string $month = null): int
    {
        $month = $month ?: now()->subMonthNoOverflow()->format('Y-m');
        $from = $month.'-01 00:00:00';
        $to = date('Y-m-d 00:00:00', strtotime($from.' +1 month'));
        $w = array_merge(['signups' => 40, 'buyers' => 40, 'conversion' => 20], (array) (((array) Settings::get('partnerProgram', []))['rankWeights'] ?? []));
        $rows = [];
        foreach (DB::table('partners')->where('status', 'active')->pluck('id') as $pid) {
            $s = DB::table('users')->where('acq_partner_id', $pid)->when(EmailVerification::enabled(), fn ($q) => $q->whereNotNull('email_verified_at'))->whereBetween('created_at', [$from, $to])->count();
            $b = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id')->where('u.acq_partner_id', $pid)->where('p.is_live', true)->where('p.amount_paise', '>', 0)
                ->whereIn('p.status', ['paid', 'partially_refunded'])->whereBetween('p.created_at', [$from, $to])->distinct('p.owner_id')->count('p.owner_id');
            $rows[$pid] = ['signups' => $s, 'buyers' => $b, 'conversion' => $s ? round($b / $s, 4) : 0];
        }
        $max = fn ($k) => max(array_merge([0], array_column($rows, $k))) ?: 1;
        $ms = $max('signups');
        $mb = $max('buyers');
        $mc = $max('conversion');
        foreach ($rows as $pid => &$r) {
            $r['score'] = (int) round($w['signups'] * $r['signups'] / $ms + $w['buyers'] * $r['buyers'] / $mb + $w['conversion'] * $r['conversion'] / $mc);
        }
        unset($r);
        uasort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);
        $i = 0;
        foreach ($rows as $pid => $r) {
            DB::table('partner_rankings')->updateOrInsert(['partner_id' => $pid, 'month' => $month], ['score' => $r['score'], 'rank' => ++$i, 'metrics_json' => json_encode($r + ['weights' => $w]), 'created_at' => now()]);
        }

        return count($rows);
    }

    /** Cron: on the 1st (IST), rank last month once. */
    public static function monthly(): void
    {
        $month = now()->subMonthNoOverflow()->format('Y-m');
        if (now('Asia/Kolkata')->day === 1 && ! DB::table('partner_rankings')->where('month', $month)->exists()) {
            self::rank($month);
        }
    }

    // ---------------------------------------------------------------- rewards (gift hampers)

    public static function saveReward(User $admin, array $v): int
    {
        $row = ['title' => $v['title'] ?? null, 'cost_paise' => isset($v['costInr']) ? (int) round($v['costInr'] * 100) : null, 'status' => $v['status'] ?? null, 'address' => $v['address'] ?? null,
            'courier' => $v['courier'] ?? null, 'tracking' => $v['tracking'] ?? null, 'note' => $v['note'] ?? null, 'updated_at' => now()];
        $row = array_filter($row, fn ($x) => $x !== null);
        if (! empty($v['id'])) {
            abort_unless(DB::table('partner_rewards')->where('id', (int) $v['id'])->update($row), 404);
            $id = (int) $v['id'];
        } else {
            abort_unless(DB::table('partners')->where('id', (int) ($v['partnerId'] ?? 0))->exists(), 404);
            $id = (int) DB::table('partner_rewards')->insertGetId($row + ['partner_id' => (int) $v['partnerId'], 'status' => $row['status'] ?? 'approved', 'created_by' => $admin->id, 'created_at' => now()]);
        }
        $rw = DB::table('partner_rewards')->where('id', $id)->first();
        if (in_array($rw->status, ['approved', 'dispatched'], true)) {
            foreach (DB::table('partner_members')->where('partner_id', $rw->partner_id)->where('role', 'manager')->pluck('user_id') as $uid) {
                self::notify((int) $uid, 'partner_reward', $rw->status === 'approved' ? 'A thank-you from CVTara' : 'Your CVTara gift is on its way', $rw->status === 'approved' ? 'Please confirm your delivery address in the Partner portal for: '.$rw->title.'.' : $rw->title.($rw->courier ? ' was sent by '.$rw->courier.($rw->tracking ? ' (tracking '.$rw->tracking.')' : '') : ' was sent').'.', 'partner-reward-'.$rw->status.':'.$id);
            }
        }
        Settings::audit($admin->id, 'partner_reward_saved', $id.' '.$rw->status);

        return $id;
    }

    /** Partner manager confirms where the reward should go. */
    public static function rewardAddress(User $u, int $id, string $address): void
    {
        $m = Partners::membership($u);
        abort_unless($m && $m->role === 'manager', 403);
        $n = DB::table('partner_rewards')->where('id', $id)->where('partner_id', $m->partner_id)->whereIn('status', ['approved', 'address'])->update(['address' => mb_substr(trim($address), 0, 600), 'status' => 'address', 'updated_at' => now()]);
        abort_unless($n, 409, 'This reward is already on its way.');
    }

    // ---------------------------------------------------------------- helpers

    private static function notify(int $uid, string $kind, string $title, string $body, string $ref): void
    {
        try {
            DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'kind' => $kind, 'title' => $title, 'body' => $body, 'link' => '/workspace.html?view=partner', 'ref' => $ref, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            ErrorLog::exception($e);
        }
    }
}
