<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.42.0 Approval Centre and agent tasks. Decisions on every approval source are recorded in approval_events; agent and
 * staff tasks have a lifecycle (ai_tasks), every move (ai_task_events) and the proof behind "Verified" (ai_task_evidence).
 * Nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_events')) {
            Schema::create('approval_events', function (Blueprint $t) {
                $t->id();
                $t->string('source', 20);
                $t->string('item_id', 64);
                $t->string('decision', 10); // approve, reject
                $t->string('reason', 1000)->default('');
                $t->unsignedBigInteger('decided_by');
                $t->string('summary', 300)->default('');
                $t->timestamp('created_at')->nullable();
                $t->index(['source', 'item_id']);
                $t->index('created_at');
            });
        }
        if (! Schema::hasTable('ai_tasks')) {
            Schema::create('ai_tasks', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('agent', 12); // seo, growth, staff
                $t->string('title', 200);
                $t->text('detail')->nullable();
                $t->string('status', 16)->default('draft');
                $t->string('priority', 10)->default('normal');
                $t->string('fingerprint', 64)->nullable(); // one open task per agent finding
                $t->unsignedBigInteger('created_by')->nullable(); // null = the agent
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->unsignedBigInteger('reviewer_id')->nullable();
                $t->timestamp('due_at')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->timestamps();
                $t->index(['agent', 'status']);
                $t->index('fingerprint');
            });
        }
        if (! Schema::hasTable('ai_task_events')) {
            Schema::create('ai_task_events', function (Blueprint $t) {
                $t->id();
                $t->uuid('task_id');
                $t->string('from_status', 16)->default('');
                $t->string('to_status', 16);
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('note', 1000)->default('');
                $t->timestamp('created_at')->nullable();
                $t->index('task_id');
            });
        }
        if (! Schema::hasTable('ai_task_evidence')) {
            Schema::create('ai_task_evidence', function (Blueprint $t) {
                $t->id();
                $t->uuid('task_id');
                $t->string('kind', 10); // link, metric, note
                $t->string('value', 1000);
                $t->unsignedBigInteger('added_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index('task_id');
            });
        }
        if (Schema::hasTable('agent_runs') && ! Schema::hasColumn('agent_runs', 'task_id')) {
            Schema::table('agent_runs', fn (Blueprint $t) => $t->uuid('task_id')->nullable());
        }
    }

    public function down(): void
    {
        // Kept on purpose: decisions and task history are records.
    }
};
