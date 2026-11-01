<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The site does not describe itself as AI-made: "ai resume builder" leaves the saved page keywords. Other keywords are kept as the owner wrote them. */
    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'seo')->first();
        if (! $row || ! is_array($seo = json_decode((string) $row->value_json, true)) || ! is_array($seo['pages'] ?? null)) {
            return;
        }
        $changed = false;
        foreach ($seo['pages'] as $path => $page) {
            $kw = (string) ($page['keywords'] ?? '');
            if ($kw === '' || ! preg_match('/\bai\b/i', $kw)) {
                continue;
            }
            $keep = array_values(array_filter(array_map('trim', explode(',', $kw)), fn ($k) => $k !== '' && ! preg_match('/\bai\b/i', $k)));
            $seo['pages'][$path]['keywords'] = implode(', ', $keep);
            $changed = true;
        }
        if ($changed) {
            DB::table('site_settings')->where('key', 'seo')->update(['value_json' => json_encode($seo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
