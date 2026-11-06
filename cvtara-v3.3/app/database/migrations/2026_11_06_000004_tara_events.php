<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Tara's record of security events (unsafe requests noticed or refused) and package offers shown and clicked,
 * so the owner can see attempts, warnings and which offers led to purchases. Kept 365 days; removed with the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tara_events')) {
            Schema::create('tara_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id')->nullable()->index();
                $t->string('kind', 20)->index();
                $t->string('category', 40)->default('');
                $t->string('severity', 10)->default('');
                $t->string('source', 30)->default('');
                $t->string('detail', 300)->default('');
                $t->string('plan', 60)->nullable();
                $t->uuid('conversation_id')->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tara_events');
    }
};
