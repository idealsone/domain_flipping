<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "What's new" notes for customers: short, dated notes on improvements the CVTara team made.
 * A signed-in customer sees the notes added since they last looked (and since they joined) once, in a small window,
 * and can open the full list from the workspace top bar at any time. The owner adds and edits notes in the console.
 * Stored in site_settings under "whatsNew"; when nothing is saved yet, the seeded notes below are used.
 * The time a customer closed the window is kept in activity_events as "whats_new_seen" (no new table needed).
 */
class WhatsNew
{
    public const KEY = 'whatsNew';

    public const TAGS = ['new' => 'New', 'improved' => 'Improved', 'fixed' => 'Fixed', 'safety' => 'Safety'];

    public const BANNED = '/\\b(ai|a\\.i\\.|artificial intelligence|anthr[o]pic|cl[a]ude|ch[a]tgpt|op[e]nai|gpt)\\b/i';

    public const SEEN_EVENT = 'whats_new_seen';

    /** Notes older than this are not pushed in the window (they stay in the list). */
    public const WINDOW_DAYS = 60;

    /** Customer-facing changes from recent releases, newest first. Plain words, no internal names. */
    public static function seed(): array
    {
        $n = fn ($id, $date, $tag, $title, $body) => ['id' => $id, 'date' => $date, 'tag' => $tag, 'title' => $title, 'body' => $body, 'published' => true, 'publishedAt' => $date.'T00:00:00+05:30'];

        return [
            $n('2026-11-01-ats-india', '2026-11-01', 'improved', 'Fairer ATS scores for Indian jobs', 'ATS Match now understands Indian degrees (B.Com, BCA, B.E., PGDM and more), experience written as "3 to 5 years", and fresher roles. Location, salary and "how to apply" lines in a job post no longer count against you.'),
            $n('2026-10-31-safety', '2026-10-31', 'safety', 'Extra protection for your account and payments', 'We added more checks against repeated sign-in attempts and automated misuse of sign-in, checkout and resume upload, so your account and payments stay safe.'),
            $n('2026-10-31-busy', '2026-10-31', 'fixed', 'Fewer errors at busy times', 'When many people use CVTara at once, pages now wait and retry instead of showing an error, and you see a friendly "try again in a moment" note if the site is very busy.'),
            $n('2026-10-31-payment-help', '2026-10-31', 'improved', 'Help when a payment does not finish', 'If a payment stops halfway, we send one short reminder with a link back to the same plan and price. It stops as soon as the payment goes through.'),
            $n('2026-10-30-look', '2026-10-30', 'improved', 'A cleaner, calmer look', 'Every page has a fresh, simpler design. Night mode is easier to read and the workspace menu is tidier.'),
            $n('2026-10-30-speed', '2026-10-30', 'fixed', 'Pages open faster', 'We fixed pages that sometimes opened as a blank white screen on slow connections. Pages now show right away.'),
            $n('2026-10-26-phone', '2026-10-26', 'new', 'Call us if you need help', 'Our support phone number is now on the Contact page, beside support@cvtara.com.'),
            $n('2026-10-24-tailor', '2026-10-24', 'new', 'Tailor your whole resume to a job in one click', 'After an ATS Match check, ask the CVTara team to rewrite your resume for that job. You review every change before anything is saved.'),
            $n('2026-10-23-cover-letter', '2026-10-23', 'new', 'Cover letters in one click', 'Make a complete cover letter from a saved resume and the job post, ready to edit and download.'),
            $n('2026-10-23-ats-fair', '2026-10-23', 'improved', 'Steadier ATS Match scores', 'The same resume and job now always get the same score, and a one-click "fix for this job" shows exactly what to add.'),
            $n('2026-10-23-import', '2026-10-23', 'fixed', 'Better resume upload', 'Two-column resumes (like Naukri PDFs) are read correctly, and uploading a resume replaces your free resume instead of failing.'),
        ];
    }

    /** Every note, newest first. */
    public static function all(): array
    {
        $saved = Settings::get(self::KEY, null);
        $list = is_array($saved) && isset($saved['entries']) && is_array($saved['entries']) ? $saved['entries'] : self::seed();
        usort($list, fn ($a, $b) => [$b['date'] ?? '', $b['publishedAt'] ?? ''] <=> [$a['date'] ?? '', $a['publishedAt'] ?? '']);

        return array_values($list);
    }

    /** What customers see: published notes, newest first, without internal fields. */
    public static function published(int $limit = 40): array
    {
        $out = [];
        foreach (self::all() as $e) {
            if (! empty($e['published'])) {
                $out[] = ['id' => $e['id'], 'date' => $e['date'], 'tag' => $e['tag'] ?? 'improved', 'title' => $e['title'], 'body' => $e['body'], 'publishedAt' => $e['publishedAt'] ?? ($e['date'].'T00:00:00Z')];
            }
        }

        return array_slice($out, 0, $limit);
    }

    public static function lastSeen(int $userId): ?string
    {
        return DB::table('activity_events')->where('owner_id', $userId)->where('event', self::SEEN_EVENT)->max('created_at');
    }

    /** The full list plus the notes this customer has not been shown yet. */
    public static function forUser($user): array
    {
        $list = self::published();
        $seen = self::lastSeen($user->id);
        $from = max(
            strtotime((string) $user->created_at) ?: 0,
            $seen ? (strtotime($seen) ?: 0) : 0, // stored in the app time zone, which is also PHP's default
            now()->subDays(self::WINDOW_DAYS)->getTimestamp()
        );
        $now = time(); // a note stamped later than now counts as now, so closing the window always clears it
        $unseen = array_values(array_filter($list, fn ($e) => min(strtotime($e['publishedAt']) ?: 0, $now) > $from));

        return ['entries' => $list, 'unseen' => array_column($unseen, 'id'), 'lastSeen' => $seen];
    }

    public static function markSeen(int $userId): void
    {
        // one row per customer is enough: keep the newest time only
        DB::table('activity_events')->where('owner_id', $userId)->where('event', self::SEEN_EVENT)->delete();
        Settings::audit($userId, self::SEEN_EVENT);
    }

    /** Save the owner's list. Keeps publishedAt for notes that were already live; stamps it now for newly published ones. */
    public static function save(array $entries, int $ownerId): array
    {
        $old = [];
        foreach (self::all() as $e) {
            $old[$e['id']] = $e;
        }
        $out = [];
        foreach ($entries as $e) {
            $id = isset($e['id']) && preg_match('/^[a-z0-9-]{3,60}$/', (string) $e['id']) ? $e['id'] : ($e['date'].'-'.Str::lower(Str::random(6)));
            $was = $old[$id] ?? null;
            $published = (bool) $e['published'];
            $publishedAt = $was['publishedAt'] ?? null;
            if ($published && (! $was || empty($was['published']) || ! $publishedAt)) {
                $publishedAt = now()->toIso8601String();
            }
            $out[] = ['id' => $id, 'date' => $e['date'], 'tag' => $e['tag'], 'title' => trim($e['title']), 'body' => trim($e['body']), 'published' => $published, 'publishedAt' => $publishedAt];
        }
        Settings::put(self::KEY, ['entries' => $out]);
        Settings::audit($ownerId, 'whats_new_saved', count($out).' notes');

        return self::all();
    }
}
