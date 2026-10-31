<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD = 'We send marketing emails only if you agree, and you can withdraw consent at any time.';

    /** Growth page: daily numbers, the 2 PM and 7 PM reports, writing-help spending, and campaign segments by college and target role. */
    public function up(): void
    {
        Schema::create('growth_snapshots', function (Blueprint $t) {
            $t->date('day')->primary();
            $t->json('metrics_json');
            $t->timestamps();
        });
        Schema::create('growth_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->date('day');
            $t->string('slot', 10);
            $t->string('band', 10)->default('');
            $t->text('body');
            $t->timestamps();
            $t->unique(['day', 'slot']);
        });
        Schema::create('ai_usage', function (Blueprint $t) {
            $t->id();
            $t->string('purpose', 40);
            $t->string('model', 60)->default('');
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('cost_paise')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->index('created_at');
        });
        Schema::table('profiles', function (Blueprint $t) {
            $t->string('college', 160)->default('');
            $t->string('target_role', 120)->default('');
            $t->string('last_company', 160)->default('');
            $t->index('college');
        });
        Schema::table('email_campaigns', function (Blueprint $t) {
            $t->string('seg_college', 160)->default('');
            $t->string('seg_role', 120)->default('');
        });
        // Saved privacy text that still has the default sentence gets the line about segments; rewritten text is left alone.
        $new = json_decode(file_get_contents(resource_path('data/defaults.json')), true)['legal']['privacy'] ?? '';
        $row = DB::table('site_settings')->where('key', 'legal')->first();
        if ($row && is_array($legal = json_decode((string) $row->value_json, true)) && is_string($legal['privacy'] ?? null)
            && str_contains($legal['privacy'], self::OLD) && ! str_contains($legal['privacy'], 'college, target role')) {
            preg_match('/'.preg_quote(self::OLD, '/').'[^\n]*/', $new, $m);
            $legal['privacy'] = str_replace(self::OLD, $m[0] ?? self::OLD, $legal['privacy']);
            DB::table('site_settings')->where('key', 'legal')->update(['value_json' => json_encode($legal), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('email_campaigns', fn (Blueprint $t) => $t->dropColumn(['seg_college', 'seg_role']));
        Schema::table('profiles', function (Blueprint $t) {
            $t->dropIndex(['college']);
            $t->dropColumn(['college', 'target_role', 'last_company']);
        });
        foreach (['ai_usage', 'growth_reports', 'growth_snapshots'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
