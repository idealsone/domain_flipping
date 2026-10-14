<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->string('coupon_code', 30)->nullable();
            $t->unsignedBigInteger('discount_paise')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('purchases', fn (Blueprint $t) => $t->dropColumn(['coupon_code', 'discount_paise']));
    }
};
