<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 3.43.0 Daily payment reconciliation: each mismatch between the gateway and CVTara's orders, until someone resolves it. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_reconciliation_records')) {
            Schema::create('payment_reconciliation_records', function (Blueprint $t) {
                $t->id();
                $t->date('day');
                $t->string('provider', 20);
                $t->string('kind', 40); // paid_not_recorded, recorded_not_paid, amount_differs
                $t->string('payment_reference', 120)->default('');
                $t->string('provider_order_id', 120)->default('');
                $t->uuid('purchase_id')->nullable();
                $t->unsignedBigInteger('gateway_paise')->nullable();
                $t->unsignedBigInteger('recorded_paise')->nullable();
                $t->string('note', 500)->default('');
                $t->timestamp('resolved_at')->nullable();
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->timestamps();
                $t->unique(['provider', 'kind', 'payment_reference', 'provider_order_id'], 'recon_unique');
                $t->index(['resolved_at', 'day']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_records');
    }
};
