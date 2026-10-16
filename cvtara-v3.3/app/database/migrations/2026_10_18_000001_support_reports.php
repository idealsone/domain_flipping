<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name', 120)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('area', 40);
            $t->string('kind', 12);
            $t->string('page', 300)->default('');
            $t->text('message');
            $t->unsignedTinyInteger('rating')->nullable();
            $t->string('status', 12)->default('new');
            $t->text('admin_note')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('viewport', 20)->nullable();
            $t->string('plan', 60)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index('owner_id');
        });
        Schema::create('support_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('report_id')->index();
            $t->string('path', 255);
            $t->unsignedInteger('bytes');
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_reports');
    }
};
