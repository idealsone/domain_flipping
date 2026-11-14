<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * More approvable SEO changes and content versions (3.45.0).
 *
 * Kinds: title and description (meta), internal links (links), main heading (h1), extra FAQ questions (faq), structured
 * data (schema: Article or SoftwareApplication, built from what the page really says) and content briefs (brief, which
 * become a writing task rather than a page change). People with SEO draft rights and the SEO agent draft; a different
 * person with SEO approve rights decides in the Approval Centre. Every change that goes live is a content version with
 * its before and after, can be undone in one click, and is checked on the live page a minute later.
 *
 * Only the programmatic SEO pages (SeoPages) can be changed this way. Nothing here writes page text by itself.
 */
class SeoContent
{
    public const KINDS = ['meta' => 'Title and description', 'links' => 'Internal links', 'h1' => 'Main heading (H1)', 'faq' => 'FAQ questions', 'schema' => 'Structured data', 'brief' => 'Content brief'];

    /** Structured data the site can add truthfully from a page's own content. */
    public const SCHEMA_TYPES = ['Article' => 'Article (guides and explainers)', 'SoftwareApplication' => 'SoftwareApplication (the free online tools)'];

    private const TOOLS = ['/resume-builder/', '/ats-checker/', '/cover-letter-generator/'];

    public static function ready(): bool
    {
        return Schema::hasTable('seo_content_versions');
    }

    /** Validates a draft and stores it as a pending proposal. Returns its id. */
    public static function propose(?User $by, string $kind, string $path, array $data, string $reason, ?string $taskId = null): string
    {
        abort_unless(isset(self::KINDS[$kind]), 422, 'Unknown kind of change.');
        abort_if(mb_strlen(trim($reason)) < 10, 422, 'Say in a sentence why this change helps.');
        $before = [];
        if ($kind !== 'brief') {
            abort_unless(SeoPages::owns(ltrim($path, '/')) && ($page = SeoPages::find($path)), 422, 'Only the ready-made SEO pages (examples, templates, tools, city and role pages) can be changed here.');
            abort_if(DB::table('seo_proposals')->where('kind', $kind)->where('path', $page['path'])->where('status', 'pending')->exists(), 422, 'A change of this kind is already waiting for this page.');
            $path = $page['path'];
        }
        $clean = fn ($s, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', (string) $s)))), 0, $max);
        $bad = fn ($s) => SeoAgent::branded($s) || preg_match('/\bAI\b|artificial intelligence|guarantee|100%|assured/i', $s);
        switch ($kind) {
            case 'meta':
                $d = ['title' => $clean($data['title'] ?? '', 70), 'description' => $clean($data['description'] ?? '', 170)];
                abort_unless(mb_strlen($d['title']) >= 15 && mb_strlen($d['description']) >= 70, 422, 'Title of at least 15 and description of at least 70 characters.');
                abort_if($bad($d['title'].' '.$d['description']), 422, 'Leave out other brands, AI and promises.');
                $before = ['title' => $page['title'], 'description' => $page['description']];
                break;
            case 'h1':
                $d = ['h1' => $clean($data['h1'] ?? '', 90)];
                abort_unless(mb_strlen($d['h1']) >= 8, 422, 'Write a heading of at least 8 characters.');
                abort_if($bad($d['h1']), 422, 'Leave out other brands, AI and promises.');
                abort_if($d['h1'] === $page['h1'], 422, 'That is already the heading.');
                $before = ['h1' => $page['h1']];
                break;
            case 'faq':
                $items = [];
                foreach (array_slice((array) ($data['items'] ?? []), 0, 5) as $q) {
                    $qq = $clean($q[0] ?? $q['q'] ?? '', 160);
                    $aa = $clean($q[1] ?? $q['a'] ?? '', 600);
                    abort_unless(mb_strlen($qq) >= 10 && mb_strlen($aa) >= 20, 422, 'Each question needs at least 10 characters and each answer at least 20.');
                    abort_if($bad($qq.' '.$aa), 422, 'Leave out other brands, AI and promises.');
                    $items[] = [$qq, $aa];
                }
                abort_unless($items, 422, 'Add at least one question and answer.');
                $have = array_map(fn ($f) => mb_strtolower($f[0]), $page['faq'] ?? []);
                abort_if(array_filter($items, fn ($i) => in_array(mb_strtolower($i[0]), $have, true)), 422, 'One of these questions is already on the page.');
                $d = ['items' => $items];
                $before = ['count' => count($page['faq'] ?? [])];
                break;
            case 'schema':
                $type = (string) ($data['type'] ?? '');
                abort_unless(isset(self::SCHEMA_TYPES[$type]), 422, 'Choose Article or SoftwareApplication.');
                abort_if($type === 'SoftwareApplication' && ! in_array($path, self::TOOLS, true), 422, 'SoftwareApplication is only true for the free online tools.');
                $d = ['type' => $type];
                $before = ['types' => self::types($page)];
                abort_if(in_array($type, $before['types'], true), 422, 'The page already has that structured data.');
                break;
            case 'links':
                $from = array_values(array_unique(array_filter(array_map(fn ($p) => '/'.trim((string) $p, '/').'/', (array) ($data['from'] ?? [])), fn ($p) => $p !== $path && SeoPages::owns(ltrim($p, '/')) && SeoPages::find($p))));
                abort_unless($from, 422, 'Choose at least one ready-made SEO page to link from.');
                $d = ['label' => $clean($data['label'] ?? '', 70), 'from' => array_slice($from, 0, 5)];
                abort_unless(mb_strlen($d['label']) >= 5, 422, 'Give the link a label of at least 5 characters.');
                $before = ['from' => []];
                break;
            default: // brief
                $d = ['topic' => $clean($data['topic'] ?? '', 120), 'keyword' => mb_strtolower($clean($data['keyword'] ?? '', 120)), 'outline' => array_values(array_filter(array_map(fn ($h) => $clean($h, 120), array_slice((array) ($data['outline'] ?? []), 0, 12)))),
                    'notes' => $clean($data['notes'] ?? '', 1000), 'cluster' => $clean($data['cluster'] ?? '', 60)];
                abort_unless(mb_strlen($d['topic']) >= 8, 422, 'Name the topic in at least 8 characters.');
                abort_if($bad($d['topic'].' '.$d['keyword']), 422, 'Leave out other brands, AI and promises.');
                $path = $path !== '' && preg_match('#^/[a-z0-9/_.\-]*$#', $path) ? $path : '/';
        }
        $id = (string) Str::uuid();
        DB::table('seo_proposals')->insert(['id' => $id, 'kind' => $kind, 'path' => $path, 'query' => mb_substr((string) ($d['keyword'] ?? ''), 0, 200), 'status' => 'pending', 'data_json' => json_encode($d),
            'before_json' => json_encode($before), 'reason' => mb_substr(trim($reason), 0, 2000), 'created_by' => $by?->id, 'task_id' => $taskId, 'created_at' => now(), 'updated_at' => now()]);
        if ($by) {
            Audit::record($by, 'seo_change_drafted', $id, null, ['kind' => $kind, 'path' => $path], '', 200, request());
        }

        return $id;
    }

    /** Structured data types the page carries today. */
    public static function types(array $page): array
    {
        return array_values(array_unique(array_map(fn ($b) => $b['@type'], SeoPages::schema($page, ''))));
    }

    /** Content briefs for the topics the clusters are missing, two a day at most, each proposed once. */
    public static function autoBriefs(int $max = 2): int
    {
        $n = 0;
        foreach (SeoOps::CLUSTERS as $name => $c) {
            foreach ($c['missing'] as $topic) {
                if ($n >= $max) {
                    return $n;
                }
                if (DB::table('seo_proposals')->where('kind', 'brief')->where('data_json', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], json_encode($topic)).'%')->exists()) {
                    continue;
                }
                self::propose(null, 'brief', $c['main'] ?? '/', ['topic' => $topic, 'keyword' => mb_strtolower(preg_replace('/\s*\(.*\)$/', '', $topic)), 'cluster' => $name,
                    'outline' => ['What it is and who it is for', 'Step by step', 'A short example', 'Common mistakes', 'Frequently asked questions'],
                    'notes' => 'Link to '.($c['main'] ?? 'the main tool page').' and two related guides. Use only facts the team can check; no invented numbers.'],
                    'The "'.$name.'" topic group has no page for this yet. A guide on it gives searchers a reason to land on CVTara and links them to the main page.');
                $n++;
            }
        }

        return $n;
    }

    // ---------------------------------------------------------------- versions

    public static function recordApply(object $p, ?User $by): void
    {
        if (! self::ready() || $p->kind === 'brief') {
            return;
        }
        DB::table('seo_content_versions')->insert(['proposal_id' => $p->id, 'path' => $p->path, 'kind' => $p->kind, 'before_json' => $p->before_json, 'after_json' => $p->data_json,
            'applied_by' => $by?->id, 'applied_at' => now(), 'verify_status' => 'pending']);
    }

    public static function recordUndo(object $p, ?User $by): void
    {
        if (self::ready()) {
            DB::table('seo_content_versions')->where('proposal_id', $p->id)->whereNull('reverted_at')
                ->update(['reverted_by' => $by?->id, 'reverted_at' => now(), 'verify_status' => 'pending', 'verify_tries' => 0, 'verify_note' => '', 'verified_at' => null]);
        }
    }

    /** Fetches the live page(s) and checks the change is there (or gone, after an undo). */
    public static function verify(int $id): object
    {
        $v = DB::table('seo_content_versions')->where('id', $id)->first();
        abort_unless($v, 404);
        $after = (array) json_decode((string) $v->after_json, true);
        $undone = $v->reverted_at !== null;
        $get = function (string $path) {
            $res = Http::timeout(12)->withHeaders(['User-Agent' => 'CVTara-site-check/1.0', 'Accept' => 'text/html'])->get(FastIndex::origin().$path);
            abort_unless($res->status() === 200, 502, 'The page answered HTTP '.$res->status().'.');

            return [(string) $res->body(), SeoOps::read((string) $res->body())];
        };
        try {
            [$html, $r] = $get($v->path);
            $there = match ($v->kind) {
                'meta' => mb_strtolower(rtrim($r['title'], '…')) !== '' && str_starts_with(mb_strtolower($after['title']), mb_strtolower(rtrim($r['title'], '…'))),
                'h1' => $r['h1'] === $after['h1'],
                'faq' => ! array_filter($after['items'], fn ($i) => ! str_contains($html, e($i[0]))),
                'schema' => in_array($after['type'], $r['schema_json'], true),
                'links' => ! array_filter($after['from'], fn ($src) => ! in_array($v->path, $get($src)[1]['links_json'], true)),
                default => false,
            };
            $ok = $undone ? ! $there : $there;
            $tries = (int) $v->verify_tries + 1;
            $status = $ok ? 'live' : ($tries >= 3 ? 'not_live' : 'pending');
            $note = $ok ? ($undone ? 'Undo confirmed on the live page.' : 'Seen on the live page.') : ($undone ? 'The change is still on the live page.' : 'Not on the live page yet.').($status === 'not_live' ? ' Rebuild ready-made pages (Speed & traffic) and check again.' : '');
        } catch (\Throwable $e) {
            $tries = (int) $v->verify_tries + 1;
            $status = $tries >= 3 ? 'failed' : 'pending';
            $note = 'Could not check: '.mb_substr($e->getMessage(), 0, 160);
        }
        DB::table('seo_content_versions')->where('id', $id)->update(['verify_status' => $status, 'verify_note' => $note, 'verify_tries' => $tries, 'verified_at' => now()]);
        if ($status !== 'pending') {
            if ($status === 'live' && ! $undone) {
                SeoOps::scanOne($v->path, (bool) DB::table('seo_pages')->where('path', $v->path)->value('important'));
                SeoAudit::run([$v->path]);
            }
            $task = DB::table('seo_proposals')->where('id', $v->proposal_id)->value('task_id');
            if ($task && DB::table('ai_tasks')->where('id', $task)->exists()) {
                DB::table('ai_task_evidence')->insert(['task_id' => $task, 'kind' => 'note', 'value' => mb_substr(self::KINDS[$v->kind].' on '.$v->path.': '.$note, 0, 1000), 'added_by' => null, 'created_at' => now()]);
            }
        }

        return DB::table('seo_content_versions')->where('id', $id)->first();
    }

    /** Cron: checks changes applied or undone more than a minute ago. */
    public static function verifyDue(int $limit = 10): array
    {
        if (! self::ready()) {
            return ['skipped' => true, 'summary' => 'Waiting for the 3.45.0 database update.'];
        }
        $due = DB::table('seo_content_versions')->where('verify_status', 'pending')->where(fn ($q) => $q->where('applied_at', '<', now()->subMinute())->orWhere('reverted_at', '<', now()->subMinute()))
            ->where(fn ($q) => $q->whereNull('verified_at')->orWhere('verified_at', '<', now()->subMinutes(10)))->orderBy('id')->limit($limit)->pluck('id');
        $live = 0;
        foreach ($due as $id) {
            $live += self::verify((int) $id)->verify_status === 'live' ? 1 : 0;
        }

        return ['summary' => 'Checked '.count($due).' change(s) on the live site, '.$live.' confirmed.', 'checked' => count($due), 'live' => $live];
    }

    public static function versions(?string $path = null, int $limit = 50): array
    {
        if (! self::ready()) {
            return [];
        }
        $q = DB::table('seo_content_versions as v')->leftJoin('users as a', 'a.id', '=', 'v.applied_by')->leftJoin('users as b', 'b.id', '=', 'v.reverted_by')->leftJoin('seo_proposals as p', 'p.id', '=', 'v.proposal_id');
        if ($path !== null) {
            $q->where('v.path', $path);
        }

        return $q->orderByDesc('v.id')->limit($limit)->get(['v.*', 'a.name as applied_name', 'b.name as reverted_name', 'p.status as proposal_status'])->map(fn ($v) => ['id' => $v->id, 'proposalId' => $v->proposal_id, 'path' => $v->path,
            'kind' => $v->kind, 'kindLabel' => self::KINDS[$v->kind] ?? $v->kind, 'before' => json_decode((string) $v->before_json, true), 'after' => json_decode((string) $v->after_json, true),
            'appliedBy' => $v->applied_name ?: ($v->applied_by ? 'Former team member' : 'Owner tools'), 'appliedAt' => $v->applied_at, 'revertedBy' => $v->reverted_name, 'revertedAt' => $v->reverted_at,
            'verifyStatus' => $v->verify_status, 'verifyNote' => $v->verify_note, 'verifiedAt' => $v->verified_at, 'canUndo' => $v->reverted_at === null && $v->proposal_status === 'approved'])->all();
    }

    /** A person confirms they reviewed the page; the page then says "Reviewed by the CVTara team" with that date. */
    public static function markReviewed(User $u, string $path): void
    {
        abort_unless(Schema::hasColumn('seo_pages', 'reviewed_at'), 422, 'Waiting for the 3.45.0 database update.');
        abort_unless(DB::table('seo_pages')->where('path', $path)->update(['reviewed_at' => now(), 'reviewed_by' => $u->id]), 404, 'That page has not been scanned yet.');
        if ($f = PageCache::file(ltrim($path, '/'))) {
            @unlink($f);
        }
        Audit::record($u, 'seo_page_reviewed', $path, null, ['at' => now()->toIso8601String()], '', 200, request());
    }

    /** For page templates: the date a person last reviewed this page, or null. */
    /**
     * 3.68.0 (owner, 12 Nov 2026): the day a public page's content last changed, shown as "Updated <date>" and sent to
     * search engines as dateModified. The latest of: the owner's own edit of a guide (updatedAt), an approved SEO change,
     * a person's review, and the agent's page scan noticing new content. Null when nothing is known, so no date is invented.
     */
    public static function updatedAt(string $path, ?array $post = null): ?string
    {
        $dates = [$post['updatedAt'] ?? null];
        try {
            if (Schema::hasTable('seo_pages')) {
                $row = DB::table('seo_pages')->where('path', $path)->first();
                $dates[] = $row->changed_at ?? null;
                $dates[] = $row->reviewed_at ?? null;
            }
            if (Schema::hasTable('seo_proposals')) {
                $dates[] = DB::table('seo_proposals')->where('path', $path)->where('status', 'approved')->max('decided_at');
            }
        } catch (\Throwable) {
        }
        $ts = array_filter(array_map(fn ($d) => $d ? strtotime((string) $d) : false, $dates));

        return $ts ? date('c', min(max($ts), time())) : null;
    }

    public static function reviewedAt(string $path): ?string
    {
        try {
            return Schema::hasColumn('seo_pages', 'reviewed_at') ? DB::table('seo_pages')->where('path', $path)->value('reviewed_at') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
