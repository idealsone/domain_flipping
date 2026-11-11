<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.55.0 Review edit history (V7 §28, AT-21). Additive only: four nullable columns on `feedback` and a new
 * `feedback_edits` table. The customer's original words are kept the first time anything is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $t) {
            if (! Schema::hasColumn('feedback', 'original_content')) {
                $t->text('original_content')->nullable();
            }
            if (! Schema::hasColumn('feedback', 'usage_ref')) {
                $t->string('usage_ref', 80)->nullable();
            }
            if (! Schema::hasColumn('feedback', 'edit_state')) {
                $t->string('edit_state', 24)->nullable();
            }
            if (! Schema::hasColumn('feedback', 'edited_at')) {
                $t->timestamp('edited_at')->nullable();
            }
        });
        if (! Schema::hasTable('feedback_edits')) {
            Schema::create('feedback_edits', function (Blueprint $t) {
                $t->id();
                $t->uuid('feedback_id')->index();
                $t->unsignedBigInteger('editor_id');
                $t->string('kind', 20);
                $t->string('reason', 300);
                $t->text('before_text');
                $t->text('after_text');
                $t->string('status', 24);
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_edits');
    }
};
