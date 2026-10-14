<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $t) {
            $t->id();
            $t->string('fingerprint', 64)->unique();
            $t->string('level', 12)->default('error');      // error | warning | info
            $t->string('source', 16)->default('server');    // server | browser | mail | payment | cron
            $t->text('message');
            $t->string('location', 500)->nullable();
            $t->string('url', 500)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->text('context')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestamp('first_seen')->useCurrent();
            $t->timestamp('last_seen')->useCurrent()->index();
            $t->timestamp('resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
