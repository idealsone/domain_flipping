<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SEO agent: changes it proposes from Search Console data, waiting for the owner's approval, and what happened to each. */
    public function up(): void
    {
        Schema::create('seo_proposals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 20);
            $t->string('path', 200);
            $t->string('query', 200)->default('');
            $t->string('status', 20)->default('pending');
            $t->json('data_json');
            $t->json('before_json');
            $t->text('reason');
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->decimal('position', 6, 2)->default(0);
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
            $t->index(['path', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_proposals');
    }
};
