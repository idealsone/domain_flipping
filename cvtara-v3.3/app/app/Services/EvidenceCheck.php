<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Candidate evidence check (3.56.0, V7 §12, §14, AT-10, R05). Before a clean download of a job version of a resume or a
 * cover letter, the text is compared with the customer's own resume it came from. Numbers, years, tools and names that
 * are not in that resume are listed, and the customer either edits them out or confirms they are true for this version.
 * No AI is used: it is a plain comparison, so it never changes the document by itself.
 */
class EvidenceCheck
{
    private const COMMON = ['i', 'a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'from', 'as', 'my', 'me', 'we', 'our', 'your', 'you', 'dear', 'sincerely', 'regards', 'thank', 'thanks', 'yours', 'hiring', 'manager', 'team', 'resume', 'summary', 'experience', 'education', 'skills', 'projects', 'certifications', 'profile', 'objective', 'present', 'current', 'india', 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december', 'jan', 'feb', 'mar', 'apr', 'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec', 'cvtara', 'linkedin', 'mr', 'ms', 'mrs', 'dr'];

    /** All the words and numbers of a document, as plain text. */
    public static function text($data): string
    {
        $out = [];
        array_walk_recursive($data, function ($v, $k) use (&$out) {
            if (is_string($v) && ! in_array($k, ['template', 'accent', 'font', 'version', 'id', 'photo', 'documentLanguage'], true) && ! preg_match('/^#[0-9a-f]{3,8}$/i', $v)) {
                $out[] = $v;
            }
        });

        return implode("\n", $out);
    }

    private static function numbers(string $t): array
    {
        preg_match_all('/(?<![\w.])\d[\d,]*(?:\.\d+)?(?:\s?%|\+)?/u', $t, $m);

        return array_values(array_unique(array_map(fn ($n) => preg_replace('/[\s,]/', '', rtrim($n, '.,')), $m[0])));
    }

    /** Tools and names: words with inner capitals or symbols (SQL, PowerBI, C++, Node.js) and capitalised words inside a sentence. */
    private static function names(string $t): array
    {
        $found = [];
        preg_match_all('/(?<![\w])([A-Za-z][A-Za-z0-9]*(?:[+#]+|\.[A-Za-z]+)?)/u', $t, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as [$w, $pos]) {
            $lw = mb_strtolower($w);
            if (mb_strlen($w) < 2 || in_array($lw, self::COMMON, true)) {
                continue;
            }
            $before = rtrim(substr($t, max(0, $pos - 3), min(3, $pos)));
            $sentenceStart = $pos === 0 || $before === '' || preg_match('/[.!?:;\n•\-–—(]$/u', $before);
            $techy = preg_match('/[+#.]|[a-z][A-Z]|^[A-Z]{2,}$|\d/', $w);
            if ($techy || (! $sentenceStart && preg_match('/^[A-Z][a-z]/', $w))) {
                $found[$lw] = $w;
            }
        }

        return $found;
    }

    /** What the new text says that the source does not: numbers, then tools and names. Each list is capped at 12. */
    public static function compare(string $source, string $out, string $allowed = ''): array
    {
        $have = mb_strtolower($source."\n".$allowed);
        $haveNums = array_flip(array_map(fn ($n) => rtrim($n, '%+'), self::numbers($source."\n".$allowed)));
        $flags = [];
        $nums = array_values(array_filter(self::numbers($out), fn ($n) => ! isset($haveNums[rtrim($n, '%+')])));
        if ($nums) {
            $flags[] = ['kind' => 'numbers', 'label' => 'Numbers, dates or results not in your resume', 'items' => array_slice($nums, 0, 12)];
        }
        $names = [];
        foreach (self::names($out) as $lw => $w) {
            if (! preg_match('/(?<![\p{L}\p{N}])'.preg_quote($lw, '/').'(?![\p{L}\p{N}])/u', $have)) {
                $names[] = $w;
            }
        }
        if ($names) {
            $flags[] = ['kind' => 'names', 'label' => 'Skills, tools or names not in your resume', 'items' => array_slice($names, 0, 12)];
        }

        return $flags;
    }

    /** The source a document was made from: a job version's own resume, or a letter's chosen resume. Null when it was written by the customer. */
    public static function source(int $ownerId, string $type, object $row): ?array
    {
        if ($type === 'resume') {
            if (empty($row->parent_id)) {
                return null;
            }
            $p = DB::table('resume_drafts')->where('id', $row->parent_id)->where('owner_id', $ownerId)->first(['content_json']);

            return $p ? ['text' => self::text(json_decode((string) $p->content_json, true) ?: []), 'allowed' => trim(($row->company ?? '').' '.($row->role ?? ''))] : null;
        }
        if (empty($row->source_resume_id)) {
            return null;
        }
        $p = DB::table('resume_drafts')->where('id', $row->source_resume_id)->where('owner_id', $ownerId)->first(['content_json']);
        if (! $p) {
            return null;
        }
        // A letter may name the employer and quote the job; the job text is allowed, the resume is the evidence.
        $job = ! empty($row->source_scan_id) ? (string) DB::table('ats_scans')->where('id', $row->source_scan_id)->where('owner_id', $ownerId)->value('job_text') : '';

        return ['text' => self::text(json_decode((string) $p->content_json, true) ?: []), 'allowed' => $job];
    }

    /** Flags for this version of a document and whether the customer already confirmed them. */
    public static function forDocument(int $ownerId, string $type, object $row): array
    {
        $src = self::source($ownerId, $type, $row);
        if (! $src) {
            return ['checked' => false, 'flags' => [], 'confirmed' => true];
        }
        $data = json_decode((string) $row->content_json, true) ?: [];
        // The employer, role and addressee the customer typed for this version are theirs to name.
        $own = implode(' ', array_map(fn ($k) => is_string($data[$k] ?? null) ? $data[$k] : '', ['company', 'role', 'recipient', 'hiringManager', 'companyAddress', 'title']));
        $flags = self::compare($src['text'], self::text($data), $src['allowed'].' '.$own);
        $ok = ! $flags || DB::table('evidence_confirmations')->where('doc_type', $type)->where('doc_id', $row->id)->where('revision', (int) $row->revision)->exists();

        return ['checked' => true, 'flags' => $flags, 'confirmed' => $ok];
    }

    public static function confirm(int $ownerId, string $type, object $row): void
    {
        $c = self::forDocument($ownerId, $type, $row);
        DB::table('evidence_confirmations')->insertOrIgnore(['owner_id' => $ownerId, 'doc_type' => $type, 'doc_id' => $row->id, 'revision' => (int) $row->revision, 'flags_json' => json_encode($c['flags'], JSON_UNESCAPED_UNICODE), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($ownerId, 'evidence_confirmed', $type.':'.$row->id.':'.$row->revision);
    }
}
