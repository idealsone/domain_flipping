<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.60.0 Website and search (V7 §31, §34; V5.0 §2, §9; V6 SEO §4-§5, §13). Additive only:
 *  - brand_profiles: the register of directory and brand listings (search consoles, social profiles, software
 *    directories) with the public URL, state, claimed / verified flags and when each was last checked.
 *  - seo_keywords.cluster: the topic cluster a keyword belongs to. The 118-keyword opportunity backlog is loaded with
 *    the page each keyword belongs to; keywords without a page yet are left unassigned as content gaps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_profiles')) {
            Schema::create('brand_profiles', function (Blueprint $t) {
                $t->id();
                $t->string('platform', 80)->unique();
                $t->string('kind', 20)->default('directory'); // search, social, directory, review
                $t->string('url', 300)->default('');
                $t->string('status', 12)->default('not_listed'); // not_listed, pending, live, needs_fix
                $t->boolean('claimed')->default(false);
                $t->boolean('verified')->default(false);
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->timestamp('last_checked_at')->nullable();
                $t->string('note', 500)->default('');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('seo_keywords') && ! Schema::hasColumn('seo_keywords', 'cluster')) {
            Schema::table('seo_keywords', fn (Blueprint $t) => $t->string('cluster', 80)->default(''));
        }
        \App\Services\BrandProfiles::seed();
        \App\Services\SeoBacklog::load();
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_profiles');
        if (Schema::hasColumn('seo_keywords', 'cluster')) {
            Schema::table('seo_keywords', fn (Blueprint $t) => $t->dropColumn('cluster'));
        }
    }
};
