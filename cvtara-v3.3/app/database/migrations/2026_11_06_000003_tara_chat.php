<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tara chat: conversations, their messages, and a log of every tool Tara ran. The same tables serve both modes: "customer"
 * (a customer's career assistant) and "owner" (the owner's business assistant); every query filters by mode. Owner actions
 * wait in tara_admin_actions until the owner presses Confirm, with the values before and after. AI usage now records
 * the customer account, so cost per customer and per package can be worked out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tara_conversations')) {
            Schema::create('tara_conversations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('mode', 10)->default('customer')->index();
                $t->string('title', 120)->default('');
                $t->text('summary')->nullable();
                $t->unsignedInteger('messages')->default(0);
                $t->string('status', 20)->default('open');
                $t->timestamp('last_at')->nullable()->index();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('tara_messages')) {
            Schema::create('tara_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('conversation_id')->index();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('mode', 10)->default('customer')->index();
                $t->string('role', 12);
                $t->mediumText('content');
                $t->text('meta_json')->nullable();
                $t->tinyInteger('rating')->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }
        if (! Schema::hasTable('tara_tool_calls')) {
            Schema::create('tara_tool_calls', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('conversation_id')->index();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('mode', 10)->default('customer')->index();
                $t->string('tool', 40)->index();
                $t->string('status', 12)->index();
                $t->string('error', 300)->default('');
                $t->unsignedInteger('ms')->default(0);
                $t->timestamp('created_at')->nullable()->index();
            });
        }
        if (! Schema::hasTable('tara_admin_actions')) {
            Schema::create('tara_admin_actions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('conversation_id')->nullable()->index();
                $t->unsignedBigInteger('admin_id')->index();
                $t->string('action', 40);
                $t->string('target', 190)->default('');
                $t->text('summary');
                $t->text('params_json');
                $t->text('before_json')->nullable();
                $t->text('after_json')->nullable();
                $t->string('status', 12)->default('pending')->index(); // pending | done | failed | cancelled | expired
                $t->string('result', 500)->default('');
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
                $t->index('created_at');
            });
        }
        if (! Schema::hasColumn('ai_usage', 'owner_id')) {
            Schema::table('ai_usage', function (Blueprint $t) {
                $t->unsignedBigInteger('owner_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_usage', 'owner_id')) {
            Schema::table('ai_usage', function (Blueprint $t) {
                $t->dropIndex(['owner_id']);
                $t->dropColumn('owner_id');
            });
        }
        Schema::dropIfExists('tara_admin_actions');
        Schema::dropIfExists('tara_tool_calls');
        Schema::dropIfExists('tara_messages');
        Schema::dropIfExists('tara_conversations');
    }
};
