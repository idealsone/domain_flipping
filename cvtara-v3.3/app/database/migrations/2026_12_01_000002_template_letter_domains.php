<?php

use App\Services\TemplateLibrary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.67.0. Built-in cover letters without a design family were all filed under "Technology" in the template library,
 * so the coverage report showed 43 Technology letters and gaps everywhere else. This files each built-in letter under
 * its real role group. Imported (package) designs and resume designs are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('template_library')) {
            return;
        }
        foreach (TemplateLibrary::LETTER_DOMAIN as $slug => $cat) {
            DB::table('template_library')->where('slug', $slug)->where('kind', 'letter')->where('origin', 'builtin')->update(['category' => $cat, 'updated_at' => now()]);
        }
        TemplateLibrary::sync();
    }

    public function down(): void {}
};
