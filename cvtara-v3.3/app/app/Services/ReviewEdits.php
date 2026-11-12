<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Review edits (V7 §28, AT-21, R13). The team may fix spelling or formatting, or remove personal details, and every
 * change is kept with the editor, reason and before/after text. Anything that could change the meaning goes to the
 * customer, who approves it or keeps their own words. Ratings, names and purchases are never changed or invented.
 */
class ReviewEdits
{
    public const KINDS = ['correction' => 'Spelling or formatting', 'redaction' => 'Personal details removed', 'material' => 'Wording change (customer approves)'];

    private static function words(string $t): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)) ?: [], fn ($w) => $w !== ''));
    }

    /** True when the new text only fixes spelling, spacing, punctuation or case. */
    public static function isCorrection(string $before, string $after): bool
    {
        $a = self::words($before);
        $b = self::words($after);
        if (! $a || abs(count($a) - count($b)) > max(1, (int) floor(count($a) * 0.1))) {
            return false;
        }
        $x = implode(' ', $a);
        $y = implode(' ', $b);
        similar_text($x, $y, $pct);
        // Negations decide the sentiment: adding or removing one is never a spelling fix.
        $neg = fn ($w) => count(array_intersect($w, ['not', 'no', 'never', 'dont', 'didnt', 'doesnt', 'wasnt', 'isnt', 'cant', 'cannot', 'wont', 'nothing', 'worst', 'bad', 'poor']));

        return $pct >= 85 && $neg($a) === $neg($b);
    }

    /** True when the new text is the old text with some parts replaced by [removed]. */
    public static function isRedaction(string $before, string $after): bool
    {
        if (! str_contains($after, '[removed]')) {
            return false;
        }
        $pool = array_count_values(self::words($before));
        foreach (self::words(str_replace('[removed]', ' ', $after)) as $w) {
            if (empty($pool[$w])) {
                return false;
            }
            $pool[$w]--;
        }

        return true;
    }

    public static function edit(User $editor, string $id, string $after, string $kind, string $reason): array
    {
        $f = DB::table('feedback')->where('id', $id)->first();
        abort_unless($f, 404);
        abort_unless(isset(self::KINDS[$kind]), 422, 'Choose what kind of change this is.');
        $after = trim(preg_replace('/[ \t]+/', ' ', $after));
        abort_if(mb_strlen($after) < 10 || mb_strlen($after) > 2000, 422, 'The review must be 10 to 2,000 characters.');
        abort_if(mb_strlen(trim($reason)) < 5, 422, 'Write a short reason for the change.');
        abort_if($f->edit_state === 'awaiting_customer', 409, 'A change is already waiting for the customer. Wait for their answer first.');
        abort_if($after === $f->content, 422, 'Nothing was changed.');
        if ($kind === 'correction') {
            abort_unless(self::isCorrection($f->content, $after), 422, 'This changes more than spelling or formatting. Send it to the customer for approval instead.');
        } elseif ($kind === 'redaction') {
            abort_unless(self::isRedaction($f->content, $after), 422, 'A redaction may only replace words with [removed]. Anything else needs the customer\'s approval.');
        } else {
            abort_unless($f->source === 'user', 422, 'This review was not written in a customer account, so nobody can approve a wording change. Add it again with fresh consent instead.');
        }
        $row = ['feedback_id' => $id, 'editor_id' => $editor->id, 'kind' => $kind, 'reason' => mb_substr(trim($reason), 0, 300), 'before_text' => $f->content, 'after_text' => $after, 'created_at' => now(), 'updated_at' => now()];
        if ($kind === 'material') {
            $eid = DB::table('feedback_edits')->insertGetId($row + ['status' => 'awaiting_customer']);
            // Hidden from the website until the customer answers; the owner publishes again afterwards.
            DB::table('feedback')->where('id', $id)->update(['edit_state' => 'awaiting_customer', 'status' => $f->status === 'published' ? 'pending' : $f->status, 'updated_at' => now()]);
            try {
                DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => $f->owner_id, 'kind' => 'review_edit', 'title' => 'Please check a change to your feedback', 'body' => 'Our team suggested clearer wording for your feedback. Approve it or keep your own words.', 'link' => '/workspace.html?view=feedback', 'ref' => 'review-edit-'.$eid, 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                ErrorLog::exception($e);
            }
        } else {
            DB::table('feedback_edits')->insert($row + ['status' => 'applied']);
            DB::table('feedback')->where('id', $id)->update(['original_content' => $f->original_content ?? $f->content, 'content' => $after, 'edited_at' => now(), 'updated_at' => now()]);
        }
        Settings::audit($editor->id, 'review_edited', $id.':'.$kind);

        return ['state' => $kind === 'material' ? 'awaiting_customer' : 'applied'];
    }

    /** The customer answers a suggested wording change on their own review. */
    public static function decide(int $ownerId, string $feedbackId, int $editId, bool $approve): void
    {
        $f = DB::table('feedback')->where('id', $feedbackId)->where('owner_id', $ownerId)->first();
        abort_unless($f, 404);
        $e = DB::table('feedback_edits')->where('id', $editId)->where('feedback_id', $feedbackId)->where('status', 'awaiting_customer')->first();
        abort_unless($e, 404, 'This change was already answered.');
        DB::transaction(function () use ($f, $e, $approve) {
            DB::table('feedback_edits')->where('id', $e->id)->update(['status' => $approve ? 'approved' : 'declined', 'decided_at' => now(), 'updated_at' => now()]);
            DB::table('feedback')->where('id', $f->id)->update($approve
                ? ['original_content' => $f->original_content ?? $f->content, 'content' => $e->after_text, 'edited_at' => now(), 'edit_state' => null, 'updated_at' => now()]
                : ['edit_state' => 'customer_declined', 'updated_at' => now()]);
        });
        Settings::audit($ownerId, $approve ? 'review_edit_approved' : 'review_edit_declined', (string) $e->id);
    }

    public static function history(string $id): array
    {
        $f = DB::table('feedback')->where('id', $id)->first(['id', 'content', 'original_content', 'consent', 'consented_at', 'usage_ref', 'edit_state', 'created_at']);
        abort_unless($f, 404);
        $edits = DB::table('feedback_edits as e')->leftJoin('users as u', 'u.id', '=', 'e.editor_id')->where('e.feedback_id', $id)->orderBy('e.id')
            ->get(['e.id', 'e.kind', 'e.reason', 'e.before_text', 'e.after_text', 'e.status', 'e.decided_at', 'e.created_at', 'u.name as editor']);

        return ['review' => $f, 'edits' => $edits, 'kinds' => self::KINDS];
    }

    /** Pending suggestions for the customer's own reviews, keyed by review id. */
    public static function pendingFor(int $ownerId): array
    {
        return DB::table('feedback_edits as e')->join('feedback as f', 'f.id', '=', 'e.feedback_id')->where('f.owner_id', $ownerId)->where('e.status', 'awaiting_customer')
            ->get(['e.id', 'e.feedback_id', 'e.after_text', 'e.reason'])->keyBy('feedback_id')->map(fn ($x) => ['id' => (int) $x->id, 'after' => $x->after_text, 'reason' => $x->reason])->all();
    }

    /** Ask for feedback only after a resume was saved, at most once every 14 days, and never after feedback was sent. */
    /** 3.65.0 answers to "Is the price fair?" in the after-save card. */
    public const PRICE_VIEWS = ['too_high', 'fair', 'great_value'];

    /** How customers answered "Is the price fair?" in the last 90 days (latest answer per customer). */
    public static function priceViews(int $days = 90): array
    {
        $rows = DB::table('activity_events')->where('event', 'price_view')->where('created_at', '>=', now()->subDays($days))->orderBy('created_at')->get(['owner_id', 'reference']);
        $last = [];
        foreach ($rows as $x) {
            $last[(int) $x->owner_id] = (string) $x->reference;
        }
        $out = array_fill_keys(self::PRICE_VIEWS, 0);
        foreach ($last as $v) {
            if (isset($out[$v])) {
                $out[$v]++;
            }
        }

        return $out + ['days' => $days];
    }

    public static function shouldAsk(int $ownerId): bool
    {
        return DB::table('resume_drafts')->where('owner_id', $ownerId)->exists()
            && ! DB::table('feedback')->where('owner_id', $ownerId)->exists()
            && ! DB::table('activity_events')->where('owner_id', $ownerId)->whereIn('event', ['feedback_prompt_shown', 'feedback_prompt_dismissed'])->where('created_at', '>=', now()->subDays(30))->exists();
    }
}
