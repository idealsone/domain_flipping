<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Writing, tone and basic grammar checks for a resume or cover letter. The rules (resources/data/writing-rules.json) run on
 * this server and send nothing anywhere. The full grammar check uses LanguageTool and runs only when the owner switches it
 * on and the customer presses it, because it sends the checked text to that service.
 */
class WritingCheck
{
    public const MAX_ISSUES = 60;

    public const GRAMMAR_DAILY = 20;

    private static ?array $rules = null;

    public static function rules(): array
    {
        return self::$rules ??= json_decode((string) file_get_contents(resource_path('data/writing-rules.json')), true) ?: [];
    }

    /** The parts of a document worth checking, each with a label the customer recognises. @return list<array{section:string,text:string,kind:string}> */
    public static function segments(string $type, array $d): array
    {
        $out = [];
        $add = function (string $section, $text, string $kind) use (&$out) {
            $text = trim((string) $text);
            if ($text !== '') {
                $out[] = ['section' => $section, 'text' => Str::limit($text, 4000, ''), 'kind' => $kind];
            }
        };
        $lines = fn ($t) => array_values(array_filter(array_map(fn ($l) => trim((string) preg_replace('/^\s*[•*\-–]\s*/u', '', $l)), preg_split('/\R/u', (string) $t)), fn ($l) => $l !== ''));
        if ($type === 'letter') {
            $add('Subject', $d['subject'] ?? '', 'line');
            $add('Greeting', $d['salutation'] ?? '', 'line');
            foreach (['opening' => 'Opening', 'evidence' => 'Your evidence', 'motivation' => 'Reason for applying', 'closing' => 'Closing'] as $k => $label) {
                $add($label, $d[$k] ?? '', 'para');
            }

            return array_slice($out, 0, 40);
        }
        $add('Summary', $d['summary'] ?? '', 'summary');
        foreach (['experience' => 'Experience', 'projects' => 'Projects'] as $k => $label) {
            foreach (array_slice((array) ($d[$k] ?? []), 0, 20) as $i => $r) {
                $name = trim((string) ($r['role'] ?? $r['name'] ?? '')) ?: trim((string) ($r['company'] ?? '')) ?: ($label.' '.($i + 1));
                foreach (array_slice($lines($r['bullets'] ?? $r['details'] ?? ''), 0, 15) as $b) {
                    $add($label.' · '.Str::limit($name, 50), $b, $k === 'experience' ? 'bullet' : 'project');
                }
            }
        }
        $add('Awards', $d['awards'] ?? '', 'para');
        $add('Skills', $d['skills'] ?? '', 'skills');

        return array_slice($out, 0, 200);
    }

    /** @return array{issues:list<array>,counts:array{writing:int,tone:int,grammar:int},checked:int,fullGrammar:bool,fullGrammarUsed:bool,fullGrammarLeft:int} */
    public static function run(string $type, array $d, ?int $userId = null, bool $full = false): array
    {
        $segs = self::segments($type, $d);
        $issues = self::local($type, $segs);
        $used = false;
        if ($full) {
            abort_unless(self::grammarEnabled(), 422, 'The full grammar check is switched off.');
            abort_unless($userId, 401);
            $issues = self::merge($issues, self::languageTool($segs, $userId));
            $used = true;
        }
        $issues = array_slice($issues, 0, self::MAX_ISSUES);
        $counts = ['writing' => 0, 'tone' => 0, 'grammar' => 0];
        foreach ($issues as $x) {
            $counts[$x['kind']]++;
        }

        return ['issues' => $issues, 'counts' => $counts, 'checked' => count($segs), 'fullGrammar' => self::grammarEnabled(), 'fullGrammarUsed' => $used, 'fullGrammarLeft' => $userId ? self::grammarLeft($userId) : 0];
    }

    private static function re(string $phrase): string
    {
        return '/(?<![\p{L}\p{N}\'’-])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}’-])/iu';
    }

    /** The rule-based checks. */
    public static function local(string $type, array $segs): array
    {
        $R = self::rules();
        $out = [];
        $per = [];
        $add = function (string $kind, string $rule, array $s, string $match, string $message, array $try = [], int $cap = 6) use (&$out, &$per) {
            $key = $rule.'|'.mb_strtolower($match);
            if (($per[$key] = ($per[$key] ?? 0) + 1) > $cap) {
                return;
            }
            $out[] = ['kind' => $kind, 'rule' => $rule, 'section' => $s['section'], 'excerpt' => self::excerpt($s['text'], $match), 'match' => $match, 'message' => $message, 'try' => array_values($try)];
        };
        $phrases = function (array $s, string $group, string $kind) use ($R, $add) {
            foreach ($R[$group] ?? [] as $w) {
                if (preg_match(self::re($w['phrase']), $s['text'], $m)) {
                    $add($kind, $group, $s, $m[0], $w['message'], $w['try'] ?? []);
                }
            }
        };
        $starts = [];
        foreach ($segs as $s) {
            $t = $s['text'];
            $prose = $s['kind'] !== 'skills';
            if ($prose) {
                $phrases($s, 'weak', 'writing');
                $phrases($s, 'india', 'writing');
                $phrases($s, 'unsure', 'tone');
                $phrases($s, 'negative', 'tone');
                if ($type === 'letter' || $s['kind'] === 'summary') {
                    $phrases($s, 'casual', 'tone');
                    $phrases($s, 'pushy', 'tone');
                }
                if (preg_match('/^myself\s+\p{Lu}/u', $t, $m) || preg_match('/(?:^|[.!?]\s+)(Myself\s+\p{Lu}\p{L}*)/u', $t, $m)) {
                    $add('writing', 'myself', $s, trim($m[1] ?? $m[0]), 'Start with “I am” rather than “Myself”.', ['I am']);
                }
            }
            if ($type === 'resume' && in_array($s['kind'], ['bullet', 'project', 'summary'], true) && preg_match('/(?<![\p{L}])(I|[Mm]y|[Mm]e|I\'m|I’m|I\'ve|I’ve)(?![\p{L}])/u', $t, $m)) {
                $add('writing', 'first-person', $s, $m[0], 'Resumes usually leave out “I” and “my”. Start with the action instead.', [], 4);
            }
            if ($s['kind'] === 'bullet' && ! preg_match('/\d/', $t) && str_word_count($t) >= 4) {
                $add('writing', 'no-number', $s, '', 'No number or result. Add one if you can: how many, how much, how fast or by what percentage.', [], 5);
            }
            $words = count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
            if (in_array($s['kind'], ['bullet', 'project'], true) && $words > 30) {
                $add('writing', 'long', $s, '', "Long bullet ({$words} words). Keep bullets under about 25 words, or split it in two.", [], 5);
            }
            if (in_array($s['kind'], ['para', 'summary'], true)) {
                foreach (preg_split('/(?<=[.!?])\s+/u', $t) as $sentence) {
                    $n = count(preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY));
                    if ($n > 35) {
                        $add('writing', 'long', $s, Str::limit($sentence, 60, ''), "Long sentence ({$n} words). Split it so it reads easily.", [], 5);
                    }
                }
            }
            if (in_array($s['kind'], ['bullet', 'project'], true) && preg_match('/^\p{L}+/u', $t, $m)) {
                $starts[mb_strtolower($m[0])][] = $s;
            }
            foreach (self::grammar($s) as [$rule, $match, $message, $try]) {
                $add('grammar', $rule, $s, $match, $message, $try);
            }
            foreach ($R['spelling'] ?? [] as $wrong => $right) {
                if (preg_match(self::re($wrong), $t, $m)) {
                    $add('grammar', 'spelling', $s, $m[0], 'Spelling.', [$right]);
                }
            }
        }
        foreach ($starts as $verb => $list) {
            if (count($list) >= 3) {
                $try = $R['verbs'][$verb] ?? [];
                $word = Str::ucfirst($verb);
                $add('writing', 'repeat-verb', $list[0], $word, count($list)." bullets start with “{$word}”. Vary the opening word so each line stands out.", $try, 1);
            }
        }
        $order = ['grammar' => 0, 'tone' => 1, 'writing' => 2];
        usort($out, fn ($a, $b) => $order[$a['kind']] <=> $order[$b['kind']]);

        return $out;
    }

    /** Basic grammar and punctuation. @return list<array{0:string,1:string,2:string,3:list<string>}> */
    private static function grammar(array $s): array
    {
        $t = $s['text'];
        $out = [];
        if (preg_match('/\S( {2,})\S/u', $t, $m)) {
            $out[] = ['double-space', $m[0], 'Two spaces in a row.', []];
        }
        if (preg_match('/(?<![\p{L}\p{N}])(\p{L}{2,})\s+\1(?![\p{L}\p{N}])/iu', $t, $m) && ! in_array(mb_strtolower($m[1]), ['that', 'had', 'very', 'bye'], true)) {
            $out[] = ['repeat-word', $m[0], 'The same word twice.', [$m[1]]];
        }
        if (preg_match('/(?<![\p{L}\'’.])i(?![\p{L}\'’.])/u', $t, $m)) {
            $out[] = ['lowercase-i', 'i', 'Write “I” as a capital.', ['I']];
        }
        preg_match_all('/([\p{L}.]*)[.!?]\s+(\p{Ll}\p{L}*)/u', $t, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $prev = mb_strtolower(trim($m[1], '.'));
            if (mb_strlen($prev) > 1 && ! in_array($prev, ['e.g', 'i.e', 'etc', 'vs', 'approx', 'no', 'nos', 'dept', 'govt', 'pvt', 'ltd', 'st', 'dr', 'mr', 'mrs', 'ms', 'sr', 'jr'], true) && ! preg_match('/\p{Lu}/u', mb_substr($m[2], 1))) {
                $out[] = ['sentence-case', $m[2], 'A new sentence should start with a capital letter.', [Str::ucfirst($m[2])]];
                break;
            }
        }
        if ($s['kind'] !== 'skills' && preg_match('/^(\p{Ll}[\p{L}]*)/u', $t, $m) && $m[1] !== 'i' && ! preg_match('/\p{Lu}/u', $m[1]) && ! preg_match('/^(https?:|www\.|e\.g)/i', $t)) {
            $out[] = ['start-case', $m[1], 'Start with a capital letter.', [Str::ucfirst($m[1])]];
        }
        if (preg_match('/\p{L}(\s+)([,;:!?]|\.(?![\p{L}\p{N}]))/u', $t, $m)) {
            $out[] = ['space-before', trim($m[0]), 'No space before punctuation.', []];
        }
        if ($s['kind'] !== 'skills' && preg_match('/\p{Ll}[,;](?=\p{L})/u', $t, $m)) {
            $out[] = ['space-after', $m[0], 'Add a space after the comma.', []];
        }
        if (preg_match('/[!?]{2,}/u', $t, $m)) {
            $out[] = ['punctuation', $m[0], 'One mark is enough in a resume or letter.', [mb_substr($m[0], 0, 1)]];
        }

        return $out;
    }

    private static function excerpt(string $text, string $match): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($match === '' || mb_strlen($text) <= 160) {
            return Str::limit($text, 160);
        }
        $at = mb_stripos($text, $match);
        $from = max(0, ($at === false ? 0 : $at) - 60);

        return ($from > 0 ? '…' : '').Str::limit(mb_substr($text, $from), 150);
    }

    // ---- Full grammar check (LanguageTool, optional) ----------------------------------------------------------------------

    public static function grammarConfig(): array
    {
        return Settings::providers()['grammar'] ?? [];
    }

    public static function grammarEnabled(): bool
    {
        return (bool) (Settings::get('integrations')['grammar'] ?? false);
    }

    public static function grammarLeft(int $userId): int
    {
        return max(0, self::GRAMMAR_DAILY - DB::table('product_usage')->where('owner_id', $userId)->where('action', 'grammar')->whereDate('created_at', today())->count());
    }

    /** Sends the document's text to LanguageTool and maps each finding back to its section. A failed call costs nothing. */
    private static function languageTool(array $segs, int $userId): array
    {
        if (self::grammarLeft($userId) <= 0) {
            throw ValidationException::withMessages(['grammar' => 'You have used today’s full grammar checks. The basic checks still work.']);
        }
        $cfg = self::grammarConfig();
        $url = rtrim((string) ($cfg['url'] ?? ''), '/') ?: 'https://api.languagetool.org';
        $text = '';
        $map = [];
        foreach ($segs as $i => $s) {
            if ($s['kind'] === 'skills') {
                continue;
            }
            $map[] = [mb_strlen($text), $i];
            $text .= $s['text']."\n\n";
            if (mb_strlen($text) > 18000) {
                break;
            }
        }
        if (trim($text) === '') {
            return [];
        }
        $body = ['text' => $text, 'language' => 'en-GB'];
        if (! empty($cfg['username']) && ! empty($cfg['premiumApiKey'])) {
            $body += ['username' => $cfg['username'], 'apiKey' => $cfg['premiumApiKey']];
        }
        try {
            $res = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->post($url.'/v2/check', $body);
            if (! $res->successful()) {
                throw new \RuntimeException('LanguageTool returned '.$res->status());
            }
            $matches = (array) $res->json('matches', []);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['grammar' => 'The full grammar check is not answering right now. The basic checks below still work. Try again later.']);
        }
        DB::table('product_usage')->insert(['id' => (string) Str::uuid(), 'owner_id' => $userId, 'action' => 'grammar', 'reference' => 'languagetool', 'created_at' => now(), 'updated_at' => now()]);
        $out = [];
        foreach (array_slice($matches, 0, 80) as $m) {
            $off = (int) ($m['offset'] ?? 0);
            $seg = null;
            foreach ($map as [$start, $i]) {
                if ($start <= $off) {
                    $seg = $segs[$i];
                }
            }
            if (! $seg) {
                continue;
            }
            $match = mb_substr($text, $off, (int) ($m['length'] ?? 0));
            $out[] = ['kind' => 'grammar', 'rule' => 'languagetool', 'section' => $seg['section'], 'excerpt' => self::excerpt($seg['text'], $match), 'match' => $match,
                'message' => Str::limit(strip_tags((string) ($m['message'] ?? 'Check this.')), 200), 'try' => array_slice(array_values(array_filter(array_map(fn ($r) => (string) ($r['value'] ?? ''), (array) ($m['replacements'] ?? [])))), 0, 3)];
        }

        return $out;
    }

    /** Adds LanguageTool findings, skipping ones the basic checks already reported for the same words in the same section. */
    private static function merge(array $issues, array $more): array
    {
        $seen = [];
        foreach ($issues as $x) {
            $seen[$x['section'].'|'.mb_strtolower($x['match'])] = true;
        }
        $grammar = [];
        foreach ($more as $x) {
            if (empty($seen[$x['section'].'|'.mb_strtolower($x['match'])])) {
                $grammar[] = $x;
            }
        }

        return [...$grammar, ...$issues];
    }
}
