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
 *
 * Release notes ship with the code (shipped()): each release adds its customer-facing notes there, and they appear on the
 * site by themselves after the deploy, stamped with the time they first went out, so customers are told about every update
 * without the owner having to type it. The owner can still edit, hide or delete them; a deleted one never comes back.
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
            $n('2026-11-12-signup', '2026-11-12', 'improved', 'Easier sign-up', 'A password now needs 8 characters instead of 12, and you can pick from many more work areas, from banking and BPO to logistics, retail and government jobs.'),
            $n('2026-11-12-versions', '2026-11-12', 'new', 'Every resume keeps its history', 'Each save keeps the earlier version, so you can look back and restore it. The copy you used for a job application stays exactly as you sent it, even when you edit your main resume later.'),
            $n('2026-11-12-original', '2026-11-12', 'safety', 'Your uploaded file, kept safely for 90 days', 'The resume file you upload is kept for 90 days so you can compare it with your improved version. You can delete it any time from Account & downloads.'),
            $n('2026-11-12-job-check', '2026-11-12', 'improved', 'Check the job before you check your resume', 'ATS Match now shows the job title, company, location and experience it read from the job post, so you can confirm it is the right job before using a check.'),
            $n('2026-11-11-menu', '2026-11-11', 'improved', 'A simpler menu', 'The workspace menu is shorter, with one place for each page, and the links inside the Help centre now take you straight to the right section.'),
            $n('2026-11-07-dashboard', '2026-11-07', 'new', 'A clearer dashboard and quicker help', 'Your dashboard shows what to do next. You can make a resume and its cover letter together, ask a question from the Help centre and see our reply in your workspace, and Tara chat on the right answers questions about your account.'),
            $n('2026-11-07-job-hunt', '2026-11-07', 'improved', 'More in Job Hunt', 'Job Hunt now includes 10 tailored resumes and 10 cover letters.'),
            $n('2026-11-06-plans', '2026-11-06', 'improved', 'Plans explained more clearly', 'The Plans page now says plainly what a free account includes and what each paid plan adds, so there are no surprises at checkout.'),
            $n('2026-11-01-ats-india', '2026-11-01', 'improved', 'Fairer ATS scores for Indian jobs', 'ATS Match now understands Indian degrees (B.Com, BCA, B.E., PGDM and more), experience written as "3 to 5 years", and fresher roles. Location, salary and "how to apply" lines in a job post no longer count against you.'),
            $n('2026-10-31-safety', '2026-10-31', 'safety', 'Extra protection for your account and payments', 'We added more checks against repeated sign-in attempts and automated misuse of sign-in, checkout and resume upload, so your account and payments stay safe.'),
            $n('2026-10-31-busy', '2026-10-31', 'fixed', 'Fewer errors at busy times', 'When many people use CVTara at once, pages now wait and retry instead of showing an error, and you see a friendly "try again in a moment" note if the site is very busy.'),
            $n('2026-10-31-payment-help', '2026-10-31', 'improved', 'Help when a payment does not finish', 'If a payment stops halfway, we send one short reminder with a link back to the same plan and price. It stops as soon as the payment goes through.'),
            $n('2026-10-30-look', '2026-10-30', 'improved', 'A cleaner, calmer look', 'Every page has a fresh, simpler design. Night mode is easier to read and the workspace menu is tidier.'),
            $n('2026-10-30-speed', '2026-10-30', 'fixed', 'Pages open faster', 'We fixed pages that sometimes opened as a blank white screen on slow connections. Pages now show right away.'),
            // 3.63.0: phone note retired (support number removed 2026-11-06).
            // $n('2026-10-26-phone', '2026-10-26', 'new', 'Call us if you need help', 'Our support phone number is now on the Contact page, beside support@cvtara.com.'),
            $n('2026-10-24-tailor', '2026-10-24', 'new', 'Tailor your whole resume to a job in one click', 'After an ATS Match check, ask the CVTara team to rewrite your resume for that job. You review every change before anything is saved.'),
            $n('2026-10-23-cover-letter', '2026-10-23', 'new', 'Cover letters in one click', 'Make a complete cover letter from a saved resume and the job post, ready to edit and download.'),
            $n('2026-10-23-ats-fair', '2026-10-23', 'improved', 'Steadier ATS Match scores', 'The same resume and job now always get the same score, and a one-click "fix for this job" shows exactly what to add.'),
            $n('2026-10-23-import', '2026-10-23', 'fixed', 'Better resume upload', 'Two-column resumes (like Naukri PDFs) are read correctly, and uploading a resume replaces your free resume instead of failing.'),
        ];
    }

    /**
     * Notes that ship with each release, newest first. Customer-facing changes only, in plain words, signed as the CVTara team,
     * never naming AI or the tools behind it. Add a release's notes here in the same change as the release itself.
     */
    public static function shipped(): array
    {
        $n = fn ($id, $date, $tag, $title, $body) => ['id' => $id, 'date' => $date, 'tag' => $tag, 'title' => $title, 'body' => $body];

        return [
            $n('2026-11-14-plans-clearer', '2026-11-14', 'improved', 'Plans that are easier to compare', 'The pricing page now shows what one job application costs in each pack, and that a ₹99 Quick Download counts toward Job Hunt if you upgrade within 7 days. The Complete Job Kit adds an Expert Review and a LinkedIn and Naukri profile rewrite to Pro. Your payment receipt now lists your order number and the first three things to do.'),
            $n('2026-11-14-support-email', '2026-11-14', 'improved', 'Write to us and get answers by email', 'Email support@cvtara.com or use the contact page, and our replies now reach your inbox in the same conversation. Just reply to our email to continue, with or without a CVTara account.'),
            $n('2026-11-14-design-pictures', '2026-11-14', 'improved', 'See every design at a glance', 'The resume template and cover letter pages now show a picture of every design, and the top menu takes you straight to the resume builder, ATS checker, interview prep and pricing.'),
            $n('2026-11-14-question-bank', '2026-11-14', 'new', 'Your own interview question bank', 'Interview practice now has hundreds of questions for your role and experience, in every interview round: HR, behavioural, situational, role skills, customer handling, leadership, integrity and career. Filter by round or skill, tick each question as you practise it and watch your progress grow. More than 350 roles are covered.'),
            $n('2026-11-13-personal-interviews', '2026-11-13', 'new', 'Mock interviews made for you', 'Each mock interview now asks about your own resume, and about your move if you are switching jobs. Two practice interviewers take turns, the interview runs for 30 minutes, and your results, with a sample answer built from your resume for each question, arrive 30 minutes later.'),
            $n('2026-11-13-dashboard', '2026-11-13', 'improved', 'A calmer, quicker dashboard', 'Your dashboard now shows one clear next step at the top, and a chart of your ATS match scores and where your applications stand. Tap a point to see the job and score. The page no longer jumps while it loads.'),
            $n('2026-11-13-quiet-chat', '2026-11-13', 'improved', 'Tara waits until you ask', 'The help chat no longer opens by itself. On phones its button is a small round icon that does not cover your buttons.'),
            $n('2026-11-12-account-menu', '2026-11-12', 'improved', 'Your account menu has moved', 'Billing, Wallet, Refer & earn and your account settings are now together under My account at the top right.'),
            $n('2026-11-12-resume-growth', '2026-11-12', 'new', 'See how your resume has grown', 'My Resumes shows your first version, your main resume today and the copy you made for each job, each with its score.'),
            $n('2026-11-12-mock-real', '2026-11-12', 'improved', 'Practise interviews like the real thing', 'Mock interviews show one question at a time, never repeat a question you have already answered, and count your practice minutes for today and this week.'),
            $n('2026-11-12-fix-card', '2026-11-12', 'improved', 'A shorter Fix for this job', 'See your match score, the words to add and what the job asks for, all in one compact card.'),
            $n('2026-11-12-interview-practice', '2026-11-12', 'new', 'Practise for your interviews', 'Open Interview practice in your account, pick your role and experience, and read real interview questions with what a good answer covers. Job Hunt includes 4 mock interviews and Pro includes 12, each with written feedback on your answers.'),
            $n('2026-11-12-full-mock', '2026-11-12', 'new', 'A full 60-minute mock interview', 'Try a complete mock interview in four short parts: about you, the role, your past experience, and real situations. It uses one mock interview from your plan, just like a standard one.'),
            $n('2026-11-12-company-research', '2026-11-12', 'new', 'Prepare for a specific company', 'Paste the company\'s website and get a short summary plus six practice questions about that company. Revision mode brings back your earlier mock interview answers so you can write a better one.'),
            $n('2026-11-12-fact-check', '2026-11-12', 'safety', 'A quick check before you download', 'When you download a resume or cover letter tailored for a job, we list any number, year, tool or name that is not in your own resume, so you can remove it or confirm it is true.'),
            $n('2026-11-12-ats-label', '2026-11-12', 'improved', 'Honest ATS-friendly labels on templates', 'Every template is now tested by exporting it and reading the text back. Only single-column designs that pass the test show the ATS-friendly label.'),
            $n('2026-11-08-help-centre', '2026-11-08', 'new', 'A new help centre', 'The customer guide is rewritten in 16 short chapters with search. The ? button in the top bar opens the chapter for the page you are on.'),
            $n('2026-11-07-support-replies', '2026-11-07', 'new', 'Talk to support inside your account', 'Every support request gets a reference number. See the team\'s replies and answer them from Support requests in the menu.'),
            $n('2026-11-07-ask-tara', '2026-11-07', 'new', 'Ask Tara for quick help', 'Tara, the CVTara help chat, answers questions about your resume, cover letters, plans and the site. Open it from the button at the bottom of your workspace.'),
        ];
    }

    /** Every note, newest first. Release notes from shipped() that the site does not have yet are added and stamped now. */
    public static function all(): array
    {
        $saved = Settings::get(self::KEY, null);
        $has = is_array($saved) && isset($saved['entries']) && is_array($saved['entries']);
        $list = $has ? $saved['entries'] : self::seed();
        $removed = $has && is_array($saved['removed'] ?? null) ? $saved['removed'] : [];
        $known = array_flip(array_merge(array_column($list, 'id'), $removed));
        $add = [];
        foreach (self::shipped() as $e) {
            if (! isset($known[$e['id']])) {
                $add[] = $e + ['published' => true, 'publishedAt' => now()->toIso8601String()];
            }
        }
        if ($add) {
            $list = array_merge($add, $list);
            Settings::put(self::KEY, ['entries' => $list, 'removed' => $removed]);
        }
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
        $saved = Settings::get(self::KEY, null);
        $removed = is_array($saved) && is_array($saved['removed'] ?? null) ? $saved['removed'] : [];
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
        // a release note the owner deleted must not come back with the next page load
        $removed = array_values(array_unique(array_merge($removed, array_diff(array_keys($old), array_column($out, 'id')))));
        Settings::put(self::KEY, ['entries' => $out, 'removed' => array_slice($removed, -300)]);
        Settings::audit($ownerId, 'whats_new_saved', count($out).' notes');

        return self::all();
    }
}
