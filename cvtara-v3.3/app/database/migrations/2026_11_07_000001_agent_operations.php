<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEO and growth agents as working systems (3.34.0): where each sign-up came from, every agent task with its result,
     * a page-by-page SEO inventory with Google's index status, a keyword database, and the growth campaign and experiment registers.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('signup_source', 20)->nullable();   // google, bing, social, referral, direct (first visit in the session, analytics consent only)
            $t->string('signup_landing', 200)->nullable(); // the first page of that visit
            $t->string('signup_campaign', 60)->nullable(); // utm_campaign of that visit, if any
        });
        Schema::create('agent_runs', function (Blueprint $t) {
            $t->id();
            $t->string('agent', 12);
            $t->string('task', 40);
            $t->string('status', 10); // ok, failed, skipped
            $t->string('summary', 500)->default('');
            $t->string('error', 500)->default('');
            $t->unsignedInteger('ms')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->index(['agent', 'started_at']);
        });
        Schema::create('seo_pages', function (Blueprint $t) {
            $t->string('path', 255)->primary();
            $t->boolean('important')->default(false);
            $t->string('section', 60)->default('');
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->string('title', 300)->default('');
            $t->string('description', 500)->default('');
            $t->string('h1', 300)->default('');
            $t->unsignedTinyInteger('h1_count')->default(0);
            $t->string('canonical', 300)->default('');
            $t->string('robots', 120)->default('');
            $t->unsignedInteger('words')->default(0);
            $t->json('links_json')->nullable();   // internal links out (paths)
            $t->json('schema_json')->nullable();  // structured data types on the page
            $t->json('issues_json')->nullable();  // technical issues found by the last scan
            $t->string('fingerprint', 40)->default('');
            $t->timestamp('changed_at')->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->string('verdict', 20)->default('');          // Google URL Inspection: PASS, NEUTRAL, FAIL, PARTIAL
            $t->string('coverage', 120)->default('');        // e.g. "Submitted and indexed", "Crawled - currently not indexed"
            $t->string('indexing_state', 60)->default('');
            $t->string('robots_state', 40)->default('');
            $t->string('fetch_state', 40)->default('');
            $t->string('google_canonical', 300)->default('');
            $t->timestamp('last_crawl_at')->nullable();
            $t->timestamp('inspected_at')->nullable();
            $t->string('inspect_error', 255)->default('');
            $t->timestamp('index_requested_at')->nullable();
            $t->string('index_requested_fp', 40)->default('');
            $t->timestamps();
            $t->index(['important', 'inspected_at']);
        });
        Schema::create('seo_keywords', function (Blueprint $t) {
            $t->id();
            $t->string('keyword', 190)->unique();
            $t->string('kind', 16);      // brand, product, commercial, informational, question, long_tail
            $t->string('intent', 16);    // navigational, transactional, commercial, informational
            $t->string('value', 8);      // high, medium, low (business value, by rule)
            $t->string('target', 255)->default('');
            $t->string('source', 16)->default('search_console'); // seed, search_console, owner
            $t->string('notes', 300)->default('');
            $t->unsignedInteger('clicks')->nullable();
            $t->unsignedInteger('impressions')->nullable();
            $t->decimal('ctr', 8, 5)->nullable();
            $t->decimal('position', 6, 2)->nullable();
            $t->decimal('prev_position', 6, 2)->nullable();
            $t->string('ranking_page', 255)->default('');
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
            $t->index(['kind', 'impressions']);
        });
        Schema::create('growth_campaign_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('channel', 20);
            $t->string('status', 12)->default('planned'); // planned, live, done, stopped
            $t->string('audience', 300)->default('');
            $t->string('problem', 500)->default('');
            $t->string('offer', 300)->default('');
            $t->text('message')->nullable();
            $t->string('landing', 200)->default('');
            $t->string('utm', 60)->default('');
            $t->string('cta', 120)->default('');
            $t->unsignedInteger('budget_inr')->default(0);
            $t->unsignedInteger('cost_inr')->default(0);
            $t->string('expected', 300)->default('');
            $t->string('consent', 300)->default('');
            $t->uuid('email_campaign_id')->nullable();
            $t->string('outreach_list', 12)->default('');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('result_note', 500)->default('');
            $t->timestamps();
        });
        Schema::create('growth_experiments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 160);
            $t->string('hypothesis', 600);
            $t->string('segment', 200)->default('');
            $t->string('metric', 30);
            $t->string('change', 600)->default('');
            $t->unsignedInteger('budget_inr')->default(0);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 12)->default('planned'); // planned, running, finished
            $t->string('decision', 12)->default('');      // continue, improve, stop
            $t->json('baseline_json')->nullable();
            $t->json('result_json')->nullable();
            $t->string('notes', 600)->default('');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['growth_experiments', 'growth_campaign_plans', 'seo_keywords', 'seo_pages', 'agent_runs'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['signup_source', 'signup_landing', 'signup_campaign']));
    }
};
