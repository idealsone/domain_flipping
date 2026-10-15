<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_events', fn (Blueprint $t) => $t->index(['event', 'created_at'], 'activity_event_created'));
        Schema::table('activity_events', fn (Blueprint $t) => $t->index('created_at', 'activity_created'));
    }

    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $t) {
            $t->dropIndex('activity_event_created');
            $t->dropIndex('activity_created');
        });
    }
};
