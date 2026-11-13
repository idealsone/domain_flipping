<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.72.0 The large interview question bank (owner, 12-13 Nov 2026: "5 lakh questions", personal to each customer).
 *
 * Questions are made from resources/data/interview-catalogue.json (built by tools/interview/build_catalogue.py):
 * 352 roles in 22 job families, 10 skills per role, three experience tiers and 66 question patterns written for CVTara,
 * about 5.8 lakh distinct questions. Nothing is copied from other sites.
 *
 *  - A question is worked out from its code when needed (practice list, mock interview, model answer) and only stored in
 *    interview_questions the first time a customer is asked it in a mock interview or asks for a model answer, so the
 *    database never holds lakhs of unused rows.
 *  - Only patterns the owner approved in the console are used (interview_bank_patterns). The owner reviews sample
 *    questions for each pattern and can withdraw one at any time; the whole bank has an on/off switch.
 *  - Codes: G-{role id}-{skill 0-9 or x}-{tier e/m/s}-{pattern}, e.g. G-1x2y3z-4-m-P07. The role id is a short hash of
 *    the role's slug, so adding roles later never changes an existing code.
 */
class InterviewBank
{
    public const TIER_KEYS = ['entry' => 'e', 'mid' => 'm', 'senior' => 's'];

    public const DIFFICULTY = ['entry' => 1, 'mid' => 2, 'senior' => 3];

    /** Per page in question practice. */
    public const PAGE = 20;

    private static ?array $cat = null;

    private static ?array $approved = null;

    public static function catalogue(): array
    {
        if (self::$cat === null) {
            $f = resource_path('data/interview-catalogue.json');
            $d = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
            $d['bySlug'] = [];
            $d['byId'] = [];
            foreach ($d['roles'] ?? [] as $i => $r) {
                $d['bySlug'][$r['slug']] = $i;
                $d['byId'][self::roleId($r['slug'])] = $i;
            }
            $d['byPattern'] = [];
            foreach ($d['patterns'] ?? [] as $i => $p) {
                $d['byPattern'][$p['code']] = $i;
            }
            self::$cat = $d;
        }

        return self::$cat;
    }

    /** Forget cached approvals (after the owner changes them, and between tests). */
    public static function flush(): void
    {
        self::$approved = null;
    }

    public static function roleId(string $slug): string
    {
        return str_pad(base_convert((string) crc32($slug), 10, 36), 7, '0', STR_PAD_LEFT);
    }

    public static function settings(): array
    {
        $s = (array) Settings::get('interviewBank', []);

        return ['enabled' => (bool) ($s['enabled'] ?? true)];
    }

    public static function ready(): bool
    {
        return Schema::hasTable('interview_bank_patterns') && self::settings()['enabled'];
    }

    public static function tierOf(string $band): string
    {
        foreach (self::catalogue()['tiers'] ?? [] as $t => $bands) {
            if (in_array($band, $bands, true)) {
                return $t;
            }
        }

        return 'entry';
    }

    /** Pattern codes the owner approved. */
    public static function approved(): array
    {
        if (self::$approved === null) {
            self::$approved = self::ready() ? DB::table('interview_bank_patterns')->where('status', 'approved')->pluck('code')->flip()->all() : [];
        }

        return self::$approved;
    }

    // ---------------------------------------------------------------- catalogue sync (migration and console)

    /** Adds the catalogue's roles to career_occupations (existing roles and the owner's edits are kept). */
    public static function syncRoles(): int
    {
        $c = self::catalogue();
        $tax = Schema::hasColumn('career_occupations', 'domain');
        $n = 0;
        foreach ($c['roles'] ?? [] as $r) {
            $f = $c['families'][$r['family']];
            $n += DB::table('career_occupations')->insertOrIgnore(['slug' => $r['slug'], 'name' => $r['name'], 'family' => $f['name'], 'industry' => $f['industry'], 'synonyms' => $r['synonyms'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]
                + ($tax ? ['domain' => $f['domain'], 'seniority' => 'Fresher, Executive, Senior executive, Team leader, Manager'] : []));
        }

        return $n;
    }

    /** Adds new patterns as drafts and refreshes the wording shown in the console. Status (approved/withdrawn) is never changed here. */
    public static function syncPatterns(): int
    {
        $n = 0;
        foreach (self::catalogue()['patterns'] ?? [] as $p) {
            $row = ['kind' => $p['kind'], 'competency' => $p['competency'], 'category' => $p['category']['mid'], 'text' => $p['text']['mid'], 'updated_at' => now()];
            if (DB::table('interview_bank_patterns')->where('code', $p['code'])->exists()) {
                DB::table('interview_bank_patterns')->where('code', $p['code'])->update($row);
            } else {
                DB::table('interview_bank_patterns')->insert($row + ['code' => $p['code'], 'status' => 'draft', 'created_at' => now()]);
                $n++;
            }
        }
        self::flush();

        return $n;
    }

    // ---------------------------------------------------------------- making questions

    private static function fill(string $t, array $role, ?string $skill): string
    {
        $name = $role['name'];
        $a = (preg_match('/^[AEIOU]/', $name) ? 'an ' : 'a ').$name;

        return strtr($t, ['{skill}' => (string) $skill, '{Skill}' => ucfirst((string) $skill), '{a_role}' => $a, '{role}' => $name]);
    }

    /** One question as a plain object (the shape Interviews::eligible() returns, plus code, category, skill and gen). */
    private static function make(int $ri, $si, string $tier, array $p): object
    {
        $c = self::catalogue();
        $role = $c['roles'][$ri];
        $skill = $si === 'x' ? null : $role['skills'][$si];
        $bands = $c['tiers'][$tier];

        return (object) [
            'id' => 0, 'vid' => 0, 'gen' => true,
            'code' => 'G-'.self::roleId($role['slug']).'-'.$si.'-'.self::TIER_KEYS[$tier].'-'.$p['code'],
            'occupation' => $role['slug'], 'competency' => $p['competency'], 'category' => $p['category'][$tier], 'difficulty' => self::DIFFICULTY[$tier],
            'min_band' => $bands[0], 'max_band' => end($bands), 'skill' => $skill, 'pattern' => $p['code'],
            'text' => self::fill($p['text'][$tier], $role, $skill),
            'guidance' => self::fill($p['guidance'], $role, $skill).' '.($c['tierTip'][$tier] ?? ''), 'example' => null,
        ];
    }

    /** Every approved question for one role at one experience level (up to about 550). Empty when the bank is off or the role is not in it. */
    public static function forRole(string $slug, string $band): array
    {
        $c = self::catalogue();
        $ri = $c['bySlug'][$slug] ?? null;
        $ok = self::approved();
        if ($ri === null || ! $ok) {
            return [];
        }
        $tier = self::tierOf($band);
        $out = [];
        foreach ($c['patterns'] as $p) {
            if (! isset($ok[$p['code']])) {
                continue;
            }
            if ($p['kind'] === 'role') {
                $out[] = self::make($ri, 'x', $tier, $p);
            } else {
                foreach (array_keys($c['roles'][$ri]['skills']) as $si) {
                    $out[] = self::make($ri, $si, $tier, $p);
                }
            }
        }

        return $out;
    }

    /** The question behind a code, while its pattern is approved and the bank is on. */
    public static function resolve(string $code): ?object
    {
        if (! preg_match('/^G-([0-9a-z]{7})-([0-9]|x)-([ems])-([PR]\d{2})$/', $code, $m)) {
            return null;
        }
        $c = self::catalogue();
        $ri = $c['byId'][$m[1]] ?? null;
        $pi = $c['byPattern'][$m[4]] ?? null;
        if ($ri === null || $pi === null || ! isset(self::approved()[$m[4]])) {
            return null;
        }
        $p = $c['patterns'][$pi];
        if (($p['kind'] === 'role') !== ($m[2] === 'x') || ($m[2] !== 'x' && ! isset($c['roles'][$ri]['skills'][(int) $m[2]]))) {
            return null;
        }

        return self::make($ri, $m[2] === 'x' ? 'x' : (int) $m[2], array_search($m[3], self::TIER_KEYS, true), $p);
    }

    /** Stores generated questions as approved bank questions (once per code) and returns them with their ids. */
    public static function materialize(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (empty($r->gen)) {
                $out[] = $r;

                continue;
            }
            $q = DB::table('interview_questions')->where('code', $r->code)->first(['id', 'current_version_id']);
            if (! $q) {
                DB::transaction(function () use ($r) {
                    if (! DB::table('interview_questions')->insertOrIgnore(['code' => $r->code, 'occupation' => $r->occupation, 'competency' => $r->competency, 'difficulty' => $r->difficulty, 'min_band' => $r->min_band, 'max_band' => $r->max_band, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()])) {
                        return; // made by a parallel request
                    }
                    $id = DB::table('interview_questions')->where('code', $r->code)->value('id');
                    $vid = DB::table('interview_question_versions')->insertGetId(['question_id' => $id, 'version' => 1, 'text' => $r->text, 'guidance' => $r->guidance, 'example' => null,
                        'source' => 'CVTara question pattern '.$r->pattern, 'rights' => 'original', 'approved_at' => now(), 'created_at' => now()]);
                    DB::table('interview_questions')->where('id', $id)->update(['current_version_id' => $vid]);
                });
                $q = DB::table('interview_questions')->where('code', $r->code)->first(['id', 'current_version_id']);
            }
            $r = clone $r;
            $r->id = (int) $q->id;
            $r->vid = (int) $q->current_version_id;
            $r->gen = false;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Picks $n questions for a mock interview: never asked to this customer before, spread over different skills and
     * patterns, in a stable order per customer and day. Competencies in $skip (introduction, closing) are left out.
     */
    public static function pick(int $uid, string $slug, string $band, int $n, array $skip = [], array $seenCodes = []): array
    {
        if ($n <= 0) {
            return [];
        }
        $seen = array_flip($seenCodes);
        $day = date('Y-m-d');
        $rows = array_values(array_filter(self::forRole($slug, $band), fn ($r) => ! isset($seen[$r->code]) && ! in_array($r->competency, $skip, true)));
        usort($rows, fn ($a, $b) => crc32($uid.':'.$a->code.':'.$day) <=> crc32($uid.':'.$b->code.':'.$day));
        $out = [];
        $skills = [];
        $pats = [];
        foreach ([true, false] as $strict) { // first pass: a different skill and pattern each time
            foreach ($rows as $r) {
                if (count($out) >= $n) {
                    break 2;
                }
                if (isset($out[$r->code]) || ($strict && (isset($skills[$r->skill ?? 'x']) || isset($pats[$r->pattern])))) {
                    continue;
                }
                $out[$r->code] = $r;
                $skills[$r->skill ?? 'x'] = true;
                $pats[$r->pattern] = true;
            }
        }

        return array_values($out);
    }

    // ---------------------------------------------------------------- customer: the personal question bank

    /** Codes this customer marked as practised or was asked in a mock interview. */
    public static function doneCodes(int $uid): array
    {
        $done = Schema::hasTable('interview_practised') ? DB::table('interview_practised')->where('owner_id', $uid)->pluck('code')->all() : [];
        $asked = DB::table('interview_answers as a')->join('interview_sessions as s', 's.id', '=', 'a.session_id')->join('interview_question_versions as v', 'v.id', '=', 'a.question_version_id')
            ->join('interview_questions as q', 'q.id', '=', 'v.question_id')->where('s.owner_id', $uid)->whereNotNull('a.answer')->pluck('q.code')->all();

        return array_values(array_unique(array_merge($done, $asked)));
    }

    public static function markPractised(User $u, string $code, bool $done = true): array
    {
        abort_unless(self::resolve($code) || DB::table('interview_questions')->where('code', $code)->where('status', 'approved')->exists(), 404, 'This question is not available.');
        if ($done) {
            DB::table('interview_practised')->insertOrIgnore(['owner_id' => $u->id, 'code' => $code, 'created_at' => now()]);
        } else {
            DB::table('interview_practised')->where('owner_id', $u->id)->where('code', $code)->delete();
        }

        return ['code' => $code, 'done' => $done, 'practised' => DB::table('interview_practised')->where('owner_id', $u->id)->count()];
    }

    /**
     * Practice page for one role and level: stored questions first (the CVTara starter bank and the owner's own imports),
     * then the generated bank, filtered by category or skill, 20 per page, with practice progress for each category.
     * $stored are Interviews::eligible() rows. Free accounts see a few and the full count.
     */
    public static function practice(int $uid, string $slug, string $band, $stored, bool $full, array $f, int $sample): array
    {
        $cat = self::catalogue();
        $labels = $cat['categories'] ?? [];
        $storedRows = collect($stored)->map(function ($r) {
            $r = clone $r;
            $r->category = self::categoryOf($r->competency);
            $r->skill = null;
            $r->gen = false;

            return $r;
        })->all();
        $all = array_merge($storedRows, self::forRole($slug, $band));
        $done = array_flip(self::doneCodes($uid));
        $cats = [];
        foreach ($all as $r) {
            $k = $r->category;
            $cats[$k] ??= ['key' => $k, 'label' => $labels[$k] ?? ucfirst($k), 'total' => 0, 'done' => 0];
            $cats[$k]['total']++;
            $cats[$k]['done'] += isset($done[$r->code]) ? 1 : 0;
        }
        $order = array_flip(array_keys($labels));
        uasort($cats, fn ($a, $b) => ($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99));
        $ri = $cat['bySlug'][$slug] ?? null;
        $skills = $ri !== null ? $cat['roles'][$ri]['skills'] : [];
        $rows = array_values(array_filter($all, fn ($r) => (empty($f['category']) || $r->category === $f['category']) && (empty($f['skill']) || $r->skill === $f['skill'])
            && (empty($f['todo']) || ! isset($done[$r->code]))));
        $total = count($all);
        if (! $full) {
            // Free and Quick Download: a taste of each kind (the starter questions first), then the plans.
            $rows = array_slice($rows, 0, $sample);
            $page = 1;
            $pages = 1;
        } else {
            $pages = max(1, (int) ceil(count($rows) / self::PAGE));
            $page = max(1, min($pages, (int) ($f['page'] ?? 1)));
            $rows = array_slice($rows, ($page - 1) * self::PAGE, self::PAGE);
        }

        return [
            'full' => $full, 'total' => $total, 'matching' => $full ? count(array_filter($all, fn ($r) => (empty($f['category']) || $r->category === $f['category']) && (empty($f['skill']) || $r->skill === $f['skill']) && (empty($f['todo']) || ! isset($done[$r->code])))) : count($rows),
            'page' => $page, 'pages' => $pages, 'categories' => array_values($cats), 'skills' => $skills, 'practised' => count(array_intersect_key($done, array_flip(array_map(fn ($r) => $r->code, $all)))),
            'questions' => array_map(fn ($r) => ['code' => $r->code, 'competency' => $r->competency, 'category' => $r->category, 'categoryLabel' => $labels[$r->category] ?? '', 'skill' => $r->skill,
                'text' => $r->text, 'guidance' => $r->guidance, 'example' => $r->example ?? null, 'roleSpecific' => $r->occupation !== null, 'done' => isset($done[$r->code])], $rows),
        ];
    }

    /** Category of a stored (starter or imported) question, from its competency. */
    public static function categoryOf(string $competency): string
    {
        return match ($competency) {
            'introduction', 'closing', 'self' => 'hr',
            'motivation', 'career' => 'motivation',
            'customer' => 'customer',
            'ethics' => 'integrity',
            'leadership' => 'leadership',
            'achievement', 'teamwork', 'conflict', 'learning' => 'behavioural',
            'problem', 'pressure' => 'situational',
            default => 'skills',
        };
    }

    // ---------------------------------------------------------------- owner console

    /** Totals for the console: how big the bank is, and how much of it customers can use now. */
    public static function stats(): array
    {
        $c = self::catalogue();
        $roles = count($c['roles'] ?? []);
        $tiers = count($c['tiers'] ?? []);
        $size = fn ($p) => $p['kind'] === 'role' ? $roles * $tiers : $roles * 10 * $tiers;
        $ok = self::approved();
        $live = 0;
        foreach ($c['patterns'] ?? [] as $p) {
            $live += isset($ok[$p['code']]) ? $size($p) : 0;
        }

        return ['settings' => self::settings(), 'families' => count($c['families'] ?? []), 'roles' => $roles, 'skills' => $roles * 10, 'patterns' => count($c['patterns'] ?? []),
            'approvedPatterns' => count($ok), 'possible' => (int) ($c['total'] ?? 0), 'live' => $live,
            'stored' => DB::table('interview_questions')->where('code', 'like', 'G-%')->count(),
            'practised' => Schema::hasTable('interview_practised') ? DB::table('interview_practised')->count() : 0, 'categories' => $c['categories'] ?? []];
    }

    /** Patterns, one page at a time, with search, filters and downloads (owner rules 26 and 31 Oct). */
    public static function patternsPage(\Illuminate\Http\Request $r): array
    {
        $q = DB::table('interview_bank_patterns as p')->leftJoin('users as u', 'u.id', '=', 'p.approved_by')
            ->select(['p.code', 'p.kind', 'p.competency', 'p.category', 'p.text', 'p.status', 'p.approved_at', 'p.updated_at', 'u.email as approvedBy']);

        return AdminTable::query($r, $q, [
            'search' => ['p.code', 'p.text'],
            'filters' => ['status' => 'p.status', 'category' => 'p.category', 'kind' => 'p.kind'],
            'date' => 'p.updated_at',
            'sorts' => ['code' => 'p.code', 'status' => 'p.status', 'category' => 'p.category'], 'sort' => 'code', 'dir' => 'asc', 'key' => 'p.code',
            'export' => ['title' => 'Interview question patterns', 'columns' => ['code' => 'Pattern', 'kind' => 'Asked per', 'category' => 'Category', 'competency' => 'Skill area', 'text' => 'Wording (mid-level)', 'status' => 'Status', 'approvedBy' => 'Approved by', 'approved_at' => 'Approved (UTC)']],
        ], fn ($x) => (array) $x + ['size' => $x->kind === 'role' ? count(self::catalogue()['roles']) * 3 : count(self::catalogue()['roles']) * 30]);
    }

    /** Sample questions for one pattern, across different roles, skills and levels, for the owner to review. */
    public static function samples(string $code, int $n = 6): array
    {
        $c = self::catalogue();
        $pi = $c['byPattern'][$code] ?? null;
        abort_if($pi === null, 404, 'Pattern not found.');
        $p = $c['patterns'][$pi];
        $out = [];
        $roles = count($c['roles']);
        $tiers = array_keys(self::TIER_KEYS);
        for ($i = 0; $i < $n; $i++) {
            $ri = (crc32($code.':'.$i) + $i * 53) % $roles;
            $si = $p['kind'] === 'role' ? 'x' : (crc32($code.'s'.$i) % 10);
            $q = self::make($ri, $si, $tiers[$i % 3], $p);
            $out[] = ['role' => $c['roles'][$ri]['name'], 'level' => $tiers[$i % 3], 'skill' => $q->skill, 'text' => $q->text, 'code' => $q->code];
        }

        return ['pattern' => ['code' => $code, 'kind' => $p['kind'], 'category' => $p['category'], 'text' => $p['text'], 'guidance' => $p['guidance'],
            'status' => DB::table('interview_bank_patterns')->where('code', $code)->value('status')], 'samples' => $out];
    }

    /** Approve or withdraw patterns ('*' = every pattern). Questions already stored stay as they are; new ones stop. */
    public static function setStatus(User $admin, $codes, string $status): int
    {
        abort_unless(in_array($status, ['approved', 'withdrawn'], true), 422);
        $q = DB::table('interview_bank_patterns');
        if ($codes !== '*') {
            $q->whereIn('code', (array) $codes);
        }
        $n = $q->where('status', '!=', $status)->update(['status' => $status, 'approved_by' => $status === 'approved' ? $admin->id : null, 'approved_at' => $status === 'approved' ? now() : null, 'updated_at' => now()]);
        self::flush();
        Settings::audit($admin->id, 'interview_bank_'.$status, is_array($codes) ? implode(',', array_slice($codes, 0, 20)) : '*');

        return $n;
    }

    public static function saveSettings(array $v): array
    {
        Settings::put('interviewBank', ['enabled' => (bool) ($v['enabled'] ?? true)]);
        self::flush();

        return self::settings();
    }
}
