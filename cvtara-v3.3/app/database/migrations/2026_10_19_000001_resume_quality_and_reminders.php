<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_drafts', function (Blueprint $t) {
            $t->unsignedTinyInteger('ats_score')->nullable();
            $t->timestamp('downloaded_at')->nullable();
            $t->string('source', 10)->default('builder');
        });
        Schema::table('cover_letter_drafts', fn (Blueprint $t) => $t->timestamp('downloaded_at')->nullable());
        Schema::table('applications', function (Blueprint $t) {
            $t->unsignedTinyInteger('reminders_sent')->default(0);
            $t->timestamp('next_reminder_at')->nullable();
            $t->boolean('reminders_off')->default(false);
            $t->index('next_reminder_at');
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $t->string('kind', 24);
            $t->string('title', 180);
            $t->string('body', 500)->default('');
            $t->string('link', 200)->default('');
            $t->string('ref', 80)->default('');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['owner_id', 'read_at']);
            $t->unique(['owner_id', 'kind', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('applications', function (Blueprint $t) {
            $t->dropIndex(['next_reminder_at']);
            $t->dropColumn(['reminders_sent', 'next_reminder_at', 'reminders_off']);
        });
        Schema::table('cover_letter_drafts', fn (Blueprint $t) => $t->dropColumn('downloaded_at'));
        Schema::table('resume_drafts', fn (Blueprint $t) => $t->dropColumn(['ats_score', 'downloaded_at', 'source']));
    }
};
