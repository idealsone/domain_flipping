<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Weekly Search Console numbers per page and per search, so the owner can see each page's impressions and searches move week to week. */
    public function up(): void
    {
        Schema::create('seo_track', function (Blueprint $t) {
            $t->id();
            $t->date('week');
            $t->string('kind', 10);
            $t->string('key', 300);
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedInteger('impressions')->default(0);
            $t->decimal('ctr', 8, 5)->default(0);
            $t->decimal('position', 6, 2)->default(0);
            $t->json('extra_json')->nullable();
            $t->timestamps();
            $t->unique(['week', 'kind', 'key']);
            $t->index(['kind', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_track');
    }
};
