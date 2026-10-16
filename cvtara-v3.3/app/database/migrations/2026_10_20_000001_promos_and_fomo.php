<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_stats', function (Blueprint $t) {
            $t->id();
            $t->string('campaign_id', 40);
            $t->date('day');
            $t->string('kind', 16);
            $t->unsignedInteger('n')->default(0);
            $t->unique(['campaign_id', 'day', 'kind']);
        });
        // Buyers may choose (at checkout) to be named, first name and city only, in the "recently started" notices. Off unless they tick it.
        Schema::table('profiles', function (Blueprint $t) {
            $t->boolean('show_in_activity')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', fn (Blueprint $t) => $t->dropColumn('show_in_activity'));
        Schema::dropIfExists('promo_stats');
    }
};
