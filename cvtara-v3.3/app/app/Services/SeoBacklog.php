<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The keyword opportunity backlog (V6 SEO §4-§5, §13): 118 searches in six clusters, each mapped to the one page that
 * should answer it, so the SEO agent tracks them and two pages never compete for the same search. Searches with no
 * page yet stay unassigned and become one planning task per cluster: validate demand in Search Console first, then
 * write a page only if it can be genuinely useful (no thin or near-duplicate pages).
 */
class SeoBacklog
{
    public static function data(): array
    {
        return (array) json_decode((string) file_get_contents(resource_path('data/keyword-backlog.json')), true);
    }

    /** The page playbook (3.62.0): rules, page template, answer-engine checklist and pillar map the agents work from. */
    public static function playbook(): array
    {
        $f = resource_path('data/seo-playbook.json');

        return is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
    }

    /**
     * Missing pillar links from the last page scan: each supporting page should link up to its pillar and each pillar
     * down to its supporting pages. Returns only gaps that the scan can prove (both pages scanned).
     */
    public static function linkPlan(): array
    {
        if (! Schema::hasTable('seo_pages')) {
            return [];
        }
        $links = DB::table('seo_pages')->whereNotNull('scanned_at')->get(['path', 'links_json'])->mapWithKeys(fn ($r) => [$r->path => (array) json_decode((string) $r->links_json, true)]);
        $out = [];
        foreach (self::playbook()['pillars'] ?? [] as $p) {
            foreach ($p['clusters'] as $c) {
                if (isset($links[$c]) && ! in_array($p['url'], $links[$c], true)) {
                    $out[] = ['from' => $c, 'to' => $p['url'], 'anchor' => $p['pillar'], 'why' => 'Supporting page does not link up to its pillar.'];
                }
                if (isset($links[$p['url']]) && ! in_array($c, $links[$p['url']], true)) {
                    $out[] = ['from' => $p['url'], 'to' => $c, 'anchor' => '', 'why' => 'Pillar does not link down to this supporting page.'];
                }
            }
        }

        return $out;
    }

    /** Adds missing keywords (never overwrites a target someone has set). Returns how many were added. */
    public static function load(): int
    {
        if (! Schema::hasTable('seo_keywords')) {
            return 0;
        }
        $n = 0;
        $cluster = Schema::hasColumn('seo_keywords', 'cluster');
        foreach (self::data()['keywords'] ?? [] as $k) {
            $q = mb_strtolower(trim($k['keyword']));
            $old = DB::table('seo_keywords')->where('keyword', $q)->first();
            if ($old) {
                $upd = [];
                if ($cluster && $old->cluster === '') {
                    $upd['cluster'] = $k['cluster'];
                }
                if ($old->target === '' && $k['target'] !== '') {
                    $upd['target'] = $k['target'];
                }
                if ($upd) {
                    DB::table('seo_keywords')->where('id', $old->id)->update($upd + ['updated_at' => now()]);
                }

                continue;
            }
            DB::table('seo_keywords')->insert(['keyword' => $q, 'target' => $k['target'], 'source' => 'seed', 'notes' => mb_substr('Backlog: '.$k['audience'].'. '.($k['target'] === '' ? 'No page yet.' : ''), 0, 300),
                'created_at' => now(), 'updated_at' => now()] + ($cluster ? ['cluster' => $k['cluster']] : []) + SeoOps::classify($q));
            $n++;
        }

        return $n;
    }

    /** Clusters with mapped and unassigned searches. */
    public static function summary(): array
    {
        $out = [];
        foreach (self::data()['keywords'] ?? [] as $k) {
            $c = $k['cluster'];
            $out[$c] ??= ['cluster' => $c, 'keywords' => 0, 'mapped' => 0, 'gaps' => []];
            $out[$c]['keywords']++;
            $t = DB::table('seo_keywords')->where('keyword', mb_strtolower($k['keyword']))->value('target');
            if ($t) {
                $out[$c]['mapped']++;
            } else {
                $out[$c]['gaps'][] = $k['keyword'];
            }
        }

        return array_values($out);
    }

    /** One SEO task per cluster listing its searches with no page yet. Deduplicated by cluster. */
    public static function gapTasks(): int
    {
        if (! Schema::hasTable('ai_tasks')) {
            return 0;
        }
        $n = 0;
        foreach (self::summary() as $c) {
            if (! $c['gaps']) {
                continue;
            }
            $before = DB::table('ai_tasks')->count();
            AgentTasks::create(null, ['agent' => 'seo', 'priority' => 'normal', 'fingerprint' => 'seo:backlog:'.sha1($c['cluster']),
                'title' => mb_substr('Plan pages for '.count($c['gaps']).' '.$c['cluster'].' searches', 0, 200),
                'detail' => "These searches from the keyword backlog have no page yet:\n- ".implode("\n- ", $c['gaps'])."\n\nFirst check demand in Search Console or a keyword tool. Then either add a section to the closest existing page or draft one useful page for a group of them. Do not make one thin page per search."
                    .self::templateNote()]);
            $n += DB::table('ai_tasks')->count() - $before;
        }

        return $n;
    }

    /** The page template in a few lines, so every planning task carries the same structure. */
    private static function templateNote(): string
    {
        $t = self::playbook()['pageTemplate'] ?? [];

        return $t ? "\n\nPage structure to follow:\n- ".implode("\n- ", array_map(fn ($x) => $x[0].': '.$x[1], $t)) : '';
    }
}
