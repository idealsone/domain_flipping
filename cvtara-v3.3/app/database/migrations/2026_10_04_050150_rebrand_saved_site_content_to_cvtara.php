<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('site_settings')->whereIn('key', ['legal', 'features', 'emails', 'blog', 'seo', 'library'])->get() as $row) {
            $updated = str_replace(['RoleDost', 'ROLEDOST', '/assets/roledost-article-cover.webp'], ['CVTara', 'CVTARA', '/assets/brand/cvtara-article-cover.webp'], $row->value_json);
            if ($updated !== $row->value_json) {
                DB::table('site_settings')->where('key', $row->key)->update(['value_json' => $updated, 'revision' => $row->revision + 1, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Editorial changes cannot be safely reversed; restore a pre-migration backup if needed.
    }
};
