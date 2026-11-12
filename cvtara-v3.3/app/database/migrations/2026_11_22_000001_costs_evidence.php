<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.56.0 (V7 §12-§14, §24-§26). Additive only: `evidence_confirmations` (a customer confirmed that facts which are not
 * in their own resume are true, per document version) and `ai_invoices` (the provider's monthly invoice total, to check
 * our own cost log against it).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evidence_confirmations')) {
            Schema::create('evidence_confirmations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('doc_type', 10);
                $t->uuid('doc_id');
                $t->unsignedInteger('revision');
                $t->text('flags_json');
                $t->timestamp('confirmed_at');
                $t->timestamps();
                $t->unique(['doc_type', 'doc_id', 'revision']);
            });
        }
        if (! Schema::hasTable('ai_invoices')) {
            Schema::create('ai_invoices', function (Blueprint $t) {
                $t->id();
                $t->string('month', 7)->unique();
                $t->string('provider', 40)->default('ai');
                $t->unsignedBigInteger('amount_paise');
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('entered_by');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_confirmations');
        Schema::dropIfExists('ai_invoices');
    }
};
