<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.64.0 AI cost cuts. Additive only: how many input tokens of each request were written to or read from the prompt
 * cache, so AI spending stays exact once caching is on (a cached read costs about a tenth of a normal input token).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage') && ! Schema::hasColumn('ai_usage', 'cache_read_tokens')) {
            Schema::table('ai_usage', function (Blueprint $t) {
                $t->unsignedInteger('cache_read_tokens')->nullable();
                $t->unsignedInteger('cache_write_tokens')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback.
    }
};
