<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.58.0 Stronger agents (V7 §29, §37; Growth plan §11-§12). Additive only:
 *  - agent_runs: run id, rules/prompt version, model (empty for rule-based work), sources read and the knowledge version
 *    the agent worked from, so every result can be traced back.
 *  - agent_reports: the SEO Head's and the Growth & Revenue agent's daily, weekly and monthly reports, one per period.
 *  - agent_corrections: a missed target with target, actual, variance, evidence, root cause, corrective action, owner,
 *    deadline, expected result and review date; each one also opens a task for approval.
 *  - config_changes: every approved change to plans, prices, features and programme rules, so agents know what changed
 *    since they last synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $t) {
            if (! Schema::hasColumn('agent_runs', 'run_uid')) {
                $t->string('run_uid', 36)->nullable()->index();
            }
            if (! Schema::hasColumn('agent_runs', 'prompt_version')) {
                $t->string('prompt_version', 40)->default('');
            }
            if (! Schema::hasColumn('agent_runs', 'model')) {
                $t->string('model', 60)->default('');
            }
            if (! Schema::hasColumn('agent_runs', 'sources_json')) {
                $t->json('sources_json')->nullable();
            }
            if (! Schema::hasColumn('agent_runs', 'knowledge_version')) {
                $t->unsignedInteger('knowledge_version')->nullable();
            }
        });
        if (! Schema::hasTable('agent_reports')) {
            Schema::create('agent_reports', function (Blueprint $t) {
                $t->id();
                $t->string('agent', 12);
                $t->string('cadence', 10); // daily, weekly, monthly
                $t->string('period', 20);  // 2026-11-12, 2026-W46, 2026-11
                $t->string('run_uid', 36)->nullable();
                $t->json('data_json')->nullable();
                $t->timestamps();
                $t->unique(['agent', 'cadence', 'period']);
            });
        }
        if (! Schema::hasTable('agent_corrections')) {
            Schema::create('agent_corrections', function (Blueprint $t) {
                $t->id();
                $t->string('agent', 12);
                $t->string('cadence', 10);
                $t->string('period', 20);
                $t->string('metric', 80);
                $t->string('target', 80)->default('');
                $t->string('actual', 80)->default('');
                $t->string('variance', 80)->default('');
                $t->string('evidence', 1000)->default('');
                $t->string('root_cause', 600)->default('');
                $t->string('action', 600)->default('');
                $t->string('owner', 80)->default('');
                $t->date('deadline')->nullable();
                $t->string('expected', 300)->default('');
                $t->date('review_date')->nullable();
                $t->uuid('task_id')->nullable();
                $t->string('status', 12)->default('open'); // open, met, missed
                $t->timestamps();
                $t->unique(['agent', 'period', 'metric']);
            });
        }
        if (! Schema::hasTable('config_changes')) {
            Schema::create('config_changes', function (Blueprint $t) {
                $t->id();
                $t->string('area', 40)->index();
                $t->string('summary', 300)->default('');
                $t->unsignedInteger('revision')->default(0);
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_changes');
        Schema::dropIfExists('agent_corrections');
        Schema::dropIfExists('agent_reports');
        Schema::table('agent_runs', fn (Blueprint $t) => $t->dropColumn(['run_uid', 'prompt_version', 'model', 'sources_json', 'knowledge_version']));
    }
};
