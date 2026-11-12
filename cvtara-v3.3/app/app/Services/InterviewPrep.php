<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 3.57.0 Interview vertical additions (V7 §16-§20, V5.6-V5.7 §5):
 *  - role search across names and designation synonyms ("telecaller" finds Inside Sales Executive);
 *  - "Research a company": one public page the customer names is read (robots.txt respected, no logins or bypassing),
 *    a short factual summary is kept with its source, and a small set of company-context practice questions is made
 *    from templates. They are labelled as practice questions, never as the employer's real questions. No AI is used;
 *  - revision mode: questions from the customer's own finished mock interviews, with their earlier answer and the
 *    feedback, to practise again. Free, nothing is evaluated, and it counts as a practice day.
 */
class InterviewPrep
{
    public const LABEL = 'CVTara practice questions based on publicly available company information; not confirmed questions from the employer.';

    public const DAILY_RESEARCH = 5;

    /** Roles whose name, family, domain or synonyms match the words typed. Best match first. */
    public static function findRoles(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $out = [];
        foreach (Interviews::occupations() as $o) {
            $name = mb_strtolower($o['name']);
            $syn = array_map('trim', explode(',', mb_strtolower((string) ($o['synonyms'] ?? ''))));
            $score = str_contains($name, $q) ? 3 : (collect($syn)->contains(fn ($s) => $s !== '' && (str_contains($s, $q) || str_contains($q, $s))) ? 2 : (str_contains(mb_strtolower($o['family'].' '.($o['domain'] ?? '')), $q) ? 1 : 0));
            if ($score) {
                $hit = $score === 2 ? collect($syn)->first(fn ($s) => $s !== '' && (str_contains($s, $q) || str_contains($q, $s))) : null;
                $out[] = ['slug' => $o['slug'], 'name' => $o['name'], 'family' => $o['family'], 'matched' => $hit, 'score' => $score];
            }
        }
        usort($out, fn ($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return array_slice($out, 0, 8);
    }

    // ---------------------------------------------------------------- research a company

    public static function research(User $u, string $company, string $url, ?string $occupation): array
    {
        $company = trim(preg_replace('/\s+/', ' ', $company));
        abort_if(mb_strlen($company) < 2 || mb_strlen($company) > 160, 422, 'Enter the company name.');
        abort_unless(preg_match('#^https?://#i', $url) && mb_strlen($url) <= 500, 422, 'Paste the company\'s own website address, starting with https://');
        if ($occupation !== null && $occupation !== '') {
            abort_unless(DB::table('career_occupations')->where('slug', $occupation)->where('active', true)->exists(), 422, 'Choose a role from the list.');
        }
        if (DB::table('company_research')->where('owner_id', $u->id)->where('created_at', '>=', now()->subDay())->count() >= self::DAILY_RESEARCH) {
            throw ValidationException::withMessages(['company' => 'You can research '.self::DAILY_RESEARCH.' companies a day. Please come back tomorrow.']);
        }
        $url = JobPage::canonical($url);
        $row = ['owner_id' => $u->id, 'company' => mb_substr($company, 0, 160), 'url' => mb_substr($url, 0, 500), 'occupation' => $occupation ?: null, 'created_at' => now(), 'updated_at' => now()];
        try {
            if (! JobPage::robotsAllows($url)) {
                $id = DB::table('company_research')->insertGetId($row + ['status' => 'blocked', 'note' => 'The site asks automatic readers not to read this page.']);

                return ['id' => $id, 'status' => 'blocked', 'message' => 'This website asks automatic readers not to read that page, so we did not. You can still practise the general questions for your role.'];
            }
            $page = JobPage::fetch($url);
        } catch (ValidationException|\Illuminate\Http\Exceptions\HttpResponseException $e) {
            DB::table('company_research')->insert($row + ['status' => 'failed', 'note' => 'The page could not be read.']);
            throw ValidationException::withMessages(['url' => 'That page could not be read (it may need a login or load its content with scripts). Try the company\'s About page.']);
        }
        $text = AtsEngine::clean($page['text']);
        $summary = self::summary($text);
        $themes = self::themes($text, $company);
        $role = $occupation ? (string) DB::table('career_occupations')->where('slug', $occupation)->value('name') : '';
        $questions = self::questions($company, $themes, $role);
        $id = DB::table('company_research')->insertGetId($row + ['status' => 'ready', 'page_title' => mb_substr($page['title'] ?? '', 0, 200), 'summary' => $summary, 'themes_json' => json_encode($themes, JSON_UNESCAPED_UNICODE),
            'questions_json' => json_encode($questions, JSON_UNESCAPED_UNICODE), 'fetched_at' => now()]);
        Settings::audit($u->id, 'company_researched', (string) $id);

        return self::show($u, $id);
    }

    public static function show(User $u, int $id): array
    {
        $r = DB::table('company_research')->where('id', $id)->where('owner_id', $u->id)->first();
        abort_unless($r, 404);

        return ['id' => (int) $r->id, 'status' => $r->status, 'company' => $r->company, 'source' => $r->url, 'pageTitle' => $r->page_title, 'readAt' => $r->fetched_at, 'summary' => $r->summary,
            'themes' => json_decode((string) $r->themes_json, true) ?: [], 'questions' => json_decode((string) $r->questions_json, true) ?: [], 'label' => self::LABEL, 'note' => $r->note];
    }

    public static function history(User $u): array
    {
        return DB::table('company_research')->where('owner_id', $u->id)->where('status', 'ready')->orderByDesc('id')->limit(10)->get(['id', 'company', 'url', 'fetched_at'])->map(fn ($r) => (array) $r)->all();
    }

    /** The first two or three plain sentences of the page, at most 400 characters. Quoted as read, with its source. */
    private static function summary(string $text): string
    {
        $out = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', preg_replace('/\s+/u', ' ', $text)) as $s) {
            $s = trim($s);
            if (mb_strlen($s) < 40 || mb_strlen($s) > 260 || preg_match('/cookie|privacy|javascript|sign in|log in|subscribe|©|copyright/i', $s)) {
                continue;
            }
            if (mb_strlen($out.' '.$s) > 400) {
                break;
            }
            $out = trim($out.' '.$s);
        }

        return $out;
    }

    /** A few words the page repeats (products, services, values), excluding the company's own name. */
    private static function themes(string $text, string $company): array
    {
        $own = array_map('mb_strtolower', preg_split('/\s+/', $company) ?: []);
        $kw = AtsEngine::keywords('', $text);
        $words = array_values(array_filter(array_map('strval', (array) ($kw['missing'] ?? [])), fn ($w) => mb_strlen($w) >= 4 && ! in_array(mb_strtolower($w), $own, true)));

        return array_slice($words, 0, 6);
    }

    /** Six original practice questions from templates: motivation, product, customer, stakeholder, ethics, operations. */
    private static function questions(string $company, array $themes, string $role): array
    {
        $t1 = $themes[0] ?? 'its products and services';
        $t2 = $themes[1] ?? 'its customers';
        $as = $role !== '' ? 'as a '.$role : 'in this role';

        return [
            ['type' => 'Motivation', 'text' => "Why do you want to work at $company $as?", 'tip' => 'Name one or two things you learned about the company from its own website and link them to your experience. Avoid general praise.'],
            ['type' => 'Product understanding', 'text' => "From what you have read, what does $company offer, and who do you think it serves? Where does $t1 fit in?", 'tip' => 'Explain it simply in two or three sentences. If you are unsure of a detail, say what you would check.'],
            ['type' => 'Customer scenario', 'text' => "A customer of $company is unhappy about $t2. How would you handle it $as?", 'tip' => 'Listen, understand the issue, give a clear next step and a time, and follow up. Use a real example from your own work if you have one.'],
            ['type' => 'Stakeholder scenario', 'text' => "Two teams at $company need your help on the same day. How do you decide what to do first?", 'tip' => 'Talk about checking urgency and impact, telling both teams honestly, and agreeing timelines.'],
            ['type' => 'Ethics', 'text' => "You notice a colleague at $company cutting a corner that could hurt customers. What do you do?", 'tip' => 'Show that you would speak up respectfully, follow the company process and put customers and honesty first.'],
            ['type' => 'Operational judgement', 'text' => "In your first 90 days at $company, what would you want to learn, and how would you measure that you are doing well?", 'tip' => 'Mention learning the products, the team and the customers, and one or two measures that fit the role.'],
        ];
    }

    // ---------------------------------------------------------------- revision mode

    /** Questions from the customer's finished mock interviews, with their earlier answer and feedback, newest first. */
    public static function revision(User $u): array
    {
        $rows = DB::table('interview_sessions as s')->join('interview_evaluations as e', 'e.session_id', '=', 's.id')->join('interview_answers as a', 'a.session_id', '=', 's.id')
            ->join('interview_question_versions as v', 'v.id', '=', 'a.question_version_id')->where('s.owner_id', $u->id)->orderByDesc('s.evaluated_at')->orderBy('a.position')->limit(60)
            ->get(['s.id', 's.occupation', 's.evaluated_at', 'a.position', 'a.answer', 'v.text', 'v.guidance', 'e.result_json']);
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->text])) {
                continue;
            }
            $seen[$r->text] = true;
            $fb = collect((json_decode((string) $r->result_json, true) ?: [])['answers'] ?? [])->firstWhere('position', (int) $r->position) ?: [];
            $out[] = ['question' => $r->text, 'guidance' => $r->guidance, 'earlier' => (string) $r->answer, 'improve' => array_slice((array) ($fb['improve'] ?? []), 0, 2), 'rewrite' => (string) ($fb['rewrite'] ?? ''), 'from' => (string) $r->evaluated_at, 'occupation' => $r->occupation];
        }
        if ($out && ! DB::table('activity_events')->where('owner_id', $u->id)->where('event', 'interview_practice')->where('reference', 'revision')->where('created_at', '>=', now('Asia/Kolkata')->startOfDay()->utc())->exists()) {
            Settings::audit($u->id, 'interview_practice', 'revision'); // a practice day for the streak (not a role)
        }

        return ['questions' => array_slice($out, 0, 20), 'note' => 'Revision is free practice: write a better answer and compare it with your earlier one. Nothing is scored or stored.'];
    }
}
