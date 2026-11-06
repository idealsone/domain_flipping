<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reviews can carry an optional company and job title (shown only with the reviewer's consent). */
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $t) {
            if (! Schema::hasColumn('feedback', 'company')) {
                $t->string('company', 120)->default('');
            }
            if (! Schema::hasColumn('feedback', 'designation')) {
                $t->string('designation', 120)->default('');
            }
        });
    }

    public function down(): void
    {
        Schema::table('feedback', fn (Blueprint $t) => $t->dropColumn(['company', 'designation']));
    }
};
