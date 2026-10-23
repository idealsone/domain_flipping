<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Customers now see Tara Expert Review, Profile Boost and the writing help as work by the Tara team. Saved copies of the old default text are updated; text the owner rewrote is left alone. */
    public function up(): void
    {
        $defaults = json_decode(file_get_contents(resource_path('data/defaults.json')), true);
        $row = DB::table('site_settings')->where('key', 'plans')->first();
        if ($row && is_array($plans = json_decode((string) $row->value_json, true))) {
            array_walk_recursive($plans, function (&$v) {
                if ($v === 'Tara AI reviews your whole resume and sends you a written report') {
                    $v = 'The Tara team reviews your whole resume and sends you a written report';
                }
            });
            DB::table('site_settings')->where('key', 'plans')->update(['value_json' => json_encode($plans), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
        $row = DB::table('site_settings')->where('key', 'legal')->first();
        if ($row && is_array($legal = json_decode((string) $row->value_json, true))) {
            foreach (['privacy', 'refund'] as $k) {
                if (isset($legal[$k]) && is_string($legal[$k]) && str_contains($legal[$k], 'Tara AI')) {
                    $legal[$k] = $defaults['legal'][$k];
                }
            }
            DB::table('site_settings')->where('key', 'legal')->update(['value_json' => json_encode($legal), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
        $row = DB::table('site_settings')->where('key', 'emails')->first();
        if ($row && is_array($emails = json_decode((string) $row->value_json, true))) {
            $fresh = collect($defaults['emails'])->keyBy('id');
            foreach ($emails as &$e) {
                if (str_contains((string) ($e['body'] ?? ''), 'Tara AI') && $fresh->has($e['id'] ?? '')) {
                    $e['body'] = $fresh[$e['id']]['body'];
                }
            }
            unset($e);
            DB::table('site_settings')->where('key', 'emails')->update(['value_json' => json_encode($emails), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
