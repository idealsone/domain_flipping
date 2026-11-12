<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 3.48.0: which wording of a prompt each AI request used, so a prompt change can be traced in costs and quality. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage') && ! Schema::hasColumn('ai_usage', 'prompt_version')) {
            Schema::table('ai_usage', fn (Blueprint $t) => $t->string('prompt_version', 16)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_usage', 'prompt_version')) {
            Schema::table('ai_usage', fn (Blueprint $t) => $t->dropColumn('prompt_version'));
        }
    }
};
