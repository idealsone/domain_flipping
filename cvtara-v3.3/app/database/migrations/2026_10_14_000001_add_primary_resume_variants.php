<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_drafts', function (Blueprint $t) {
            $t->boolean('is_primary')->default(false)->index();
            $t->uuid('parent_id')->nullable();
            $t->text('job_description')->nullable();
        });
        // The oldest draft of each existing user becomes the primary resume.
        foreach (DB::table('resume_drafts')->select('owner_id')->distinct()->pluck('owner_id') as $owner) {
            $first = DB::table('resume_drafts')->where('owner_id', $owner)->orderBy('created_at')->value('id');
            DB::table('resume_drafts')->where('id', $first)->update(['is_primary' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('resume_drafts', fn (Blueprint $t) => $t->dropColumn(['is_primary', 'parent_id', 'job_description']));
    }
};
