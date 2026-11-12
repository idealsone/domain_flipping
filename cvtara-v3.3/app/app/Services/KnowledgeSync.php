<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared knowledge the agents work from (V7 §29): every saved change to plans, prices, features, programme rules,
 * promotions, legal pages and agent rules is written to the change feed. The knowledge version is the newest change's
 * number; each agent records the version it last worked from, so the console shows which agents are behind.
 */
class KnowledgeSync
{
    /** Settings areas the agents must know about, with the words the change feed shows. */
    public const WATCH = [
        'plans' => 'Plans and prices', 'public' => 'Public site wording', 'site' => 'Site details', 'referrals' => 'Referral rules', 'refunds' => 'Refund rules',
        'promos' => 'Offers and coupons', 'partnerProgram' => 'Partner programme', 'interviews' => 'Interview practice', 'costControls' => 'Cost limits',
        'legal' => 'Legal pages', 'lifecycle' => 'Customer emails', 'growth' => 'Growth targets', 'growthCosts' => 'Growth costs', 'seoAgent' => 'SEO agent rules',
        'growthOps' => 'Growth agent rules', 'agentLevels' => 'Agent permissions', 'aiPrompts' => 'Writing instructions', 'serviceSettings' => 'Service settings',
    ];

    public const AGENTS = ['seo' => 'SEO Head', 'growth' => 'Growth & Revenue'];

    private static ?bool $ready = null;

    private static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('config_changes');
    }

    /** Called by Settings::put. Only watched areas are recorded. */
    public static function changed(string $key, string $summary = ''): void
    {
        if (! isset(self::WATCH[$key]) || ! self::ready()) {
            return;
        }
        try {
            $user = auth()->id();
        } catch (\Throwable) {
            $user = null;
        }
        DB::table('config_changes')->insert(['area' => $key, 'summary' => mb_substr($summary !== '' ? $summary : self::WATCH[$key].' changed', 0, 300),
            'revision' => (int) DB::table('site_settings')->where('key', $key)->value('revision'), 'actor_id' => $user, 'created_at' => now()]);
    }

    public static function version(): ?int
    {
        return self::ready() ? (int) DB::table('config_changes')->max('id') : null;
    }

    /** The agent has just worked from the current knowledge. */
    public static function mark(string $agent): void
    {
        if (! self::ready() || ! isset(self::AGENTS[$agent])) {
            return;
        }
        DB::table('site_settings')->updateOrInsert(['key' => 'agentSync'], ['value_json' => json_encode(array_merge(self::synced(), [$agent => ['version' => self::version(), 'at' => now()->toIso8601String()]])), 'updated_at' => now()]);
    }

    /** Read without going through Settings::get's memo, so a mark in the same request is seen. */
    private static function synced(): array
    {
        $j = DB::table('site_settings')->where('key', 'agentSync')->value('value_json');

        return is_string($j) ? (array) json_decode($j, true) : [];
    }

    /** Per agent: version worked from, when, how many changes since, and whether it is stale. */
    public static function status(): array
    {
        $now = self::version();
        $out = [];
        foreach (self::AGENTS as $k => $label) {
            $s = self::synced()[$k] ?? null;
            $v = $s['version'] ?? null;
            $behind = $now === null ? 0 : (int) DB::table('config_changes')->where('id', '>', (int) $v)->count();
            $out[] = ['agent' => $k, 'label' => $label, 'version' => $v, 'syncedAt' => $s['at'] ?? null, 'changesSince' => $behind,
                'stale' => $s === null || ($behind > 0 && now()->diffInHours(\Carbon\Carbon::parse($s['at']), true) > 24)];
        }

        return ['version' => $now, 'agents' => $out];
    }

    public static function feed(int $limit = 30): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table('config_changes')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => ['id' => (int) $r->id, 'area' => $r->area, 'label' => self::WATCH[$r->area] ?? $r->area,
            'summary' => $r->summary, 'revision' => (int) $r->revision, 'by' => $r->actor_id ? DB::table('users')->where('id', $r->actor_id)->value('name') : null, 'at' => AgentLog::iso($r->created_at)])->all();
    }
}
