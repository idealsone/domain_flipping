<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 3.38.0: every AI request (successful or not) is recorded with its outcome, how long it took and the request reference. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage')) {
            return;
        }
        Schema::table('ai_usage', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_usage', 'status')) {
                $t->string('status', 20)->default('ok'); // ok, refusal, empty, rate_limit, timeout, auth, error
            }
            if (! Schema::hasColumn('ai_usage', 'latency_ms')) {
                $t->unsignedInteger('latency_ms')->nullable();
            }
            if (! Schema::hasColumn('ai_usage', 'request_id')) {
                $t->string('request_id', 16)->default('');
            }
            if (! Schema::hasColumn('ai_usage', 'error')) {
                $t->string('error', 200)->default('');
            }
        });
    }

    public function down(): void
    {
        // Columns are kept: they hold history and nothing reads them if the code is rolled back.
    }
};
