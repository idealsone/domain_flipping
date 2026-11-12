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
                'detail' => "These searches from the keyword backlog have no page yet:\n- ".implode("\n- ", $c['gaps'])."\n\nFirst check demand in Search Console or a keyword tool. Then either add a section to the closest existing page or draft one useful page for a group of them. Do not make one thin page per search."]);
            $n += DB::table('ai_tasks')->count() - $before;
        }

        return $n;
    }
}
