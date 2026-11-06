<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.35.0: the operating layer the SEO and growth agents work from.
 * - customer_events: one row per meaningful customer action (sign-up, first resume, ATS check, checkout, payment, refund...),
 *   written idempotently (unique dedupe key) from the tables that already hold the facts. No resume text or personal details.
 * - page_views gains the signed-in account and the referring site, so pricing visits and link/AI referral visits can be counted.
 * - purchases gain the last-touch source of the visit in which checkout started (first touch stays on the user row).
 * - link_prospects: the link-building and digital PR register. ai_visibility_tests: repeatable AI answer checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_events')) {
            Schema::create('customer_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('type', 30);
                $t->string('ref', 80)->default('');
                $t->string('meta', 300)->default('');
                $t->timestamp('occurred_at')->nullable();
                $t->string('dedupe', 120)->unique();
                $t->timestamp('created_at')->nullable();
                $t->index(['type', 'occurred_at']);
                $t->index(['owner_id', 'occurred_at']);
            });
        }
        Schema::table('page_views', function (Blueprint $t) {
            if (! Schema::hasColumn('page_views', 'owner_id')) {
                $t->unsignedBigInteger('owner_id')->nullable()->index();
            }
            if (! Schema::hasColumn('page_views', 'ref_host')) {
                $t->string('ref_host', 120)->nullable()->index();
            }
        });
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'signup_ref_host')) {
                $t->string('signup_ref_host', 120)->nullable();
            }
        });
        Schema::table('purchases', function (Blueprint $t) {
            if (! Schema::hasColumn('purchases', 'touch_source')) {
                $t->string('touch_source', 20)->nullable();
                $t->string('touch_landing', 200)->nullable();
                $t->string('touch_campaign', 60)->nullable();
            }
        });
        if (! Schema::hasTable('link_prospects')) {
            Schema::create('link_prospects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('domain', 120)->index();
                $t->string('url', 500)->default('');
                $t->string('method', 30)->default('resource');
                $t->string('contact_name', 120)->default('');
                $t->string('contact_email', 190)->default('');
                $t->string('contact_source', 200)->default('');
                $t->unsignedTinyInteger('relevance')->default(0);
                $t->unsignedTinyInteger('quality')->default(0);
                $t->string('spam_risk', 10)->default('unknown');
                $t->string('status', 20)->default('prospect')->index();
                $t->string('pitch', 2000)->default('');
                $t->date('next_follow_up')->nullable();
                $t->timestamp('last_contact_at')->nullable();
                $t->string('response', 1000)->default('');
                $t->string('publication_url', 500)->default('');
                $t->string('target_url', 300)->default('');
                $t->string('link_attr', 12)->default('');
                $t->string('anchor', 200)->default('');
                $t->string('verify_state', 20)->default('');
                $t->string('verify_note', 300)->default('');
                $t->timestamp('verified_at')->nullable();
                $t->unsignedInteger('cost_inr')->default(0);
                $t->unsignedTinyInteger('score')->default(0);
                $t->string('notes', 1000)->default('');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_visibility_tests')) {
            Schema::create('ai_visibility_tests', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->date('tested_on')->index();
                $t->string('platform', 30)->index();
                $t->string('model', 80)->default('');
                $t->string('region', 40)->default('India');
                $t->string('prompt', 300);
                $t->boolean('mentioned')->default(false);
                $t->boolean('recommended')->default(false);
                $t->string('accurate', 10)->default('');
                $t->boolean('linked')->default(false);
                $t->string('cited_url', 500)->default('');
                $t->string('competitors', 500)->default('');
                $t->string('sources', 1000)->default('');
                $t->string('missing', 1000)->default('');
                $t->text('excerpt')->nullable();
                $t->string('conditions', 300)->default('');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_tests');
        Schema::dropIfExists('link_prospects');
        Schema::dropIfExists('customer_events');
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn(['touch_source', 'touch_landing', 'touch_campaign']));
        Schema::table('page_views', fn (Blueprint $t) => $t->dropColumn(['owner_id', 'ref_host']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('signup_ref_host'));
    }
};
