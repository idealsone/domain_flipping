<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The candidate-type library adds 30 resume designs and 30 cover letters. A saved display limit that still equals the old
     * full catalogue (91 designs, 26 letters) is raised so the new designs are not cut off; any other owner choice is kept.
     */
    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'features')->first();
        if (! $row) {
            return;
        }
        $f = json_decode($row->value_json, true);
        if (! is_array($f)) {
            return;
        }
        $changed = false;
        foreach ([['templates', 91, 121], ['letter', 26, 56]] as [$k, $old, $new]) {
            if ((int) ($f[$k]['limit'] ?? 0) === $old) {
                $f[$k]['limit'] = $new;
                $changed = true;
            }
        }
        if ($changed) {
            DB::table('site_settings')->where('key', 'features')->update(['value_json' => json_encode($f, JSON_UNESCAPED_UNICODE), 'revision' => (int) $row->revision + 1, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
