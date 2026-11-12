<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.61.0 Test payment review (V4.8 §6; owner OK 12 Nov 2026). Additive only: the owner's mark on an order (test or
 * real). Orders are never changed or deleted; an order marked test is simply left out of revenue figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_marks')) {
            Schema::create('payment_marks', function (Blueprint $t) {
                $t->uuid('purchase_id')->primary();
                $t->string('mark', 8); // test, real
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('marked_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback.
    }
};
