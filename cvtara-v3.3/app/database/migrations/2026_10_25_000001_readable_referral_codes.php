<?php

use App\Services\Referrals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Referral links become name-and-number codes (priya-4821). The random code each account had stays valid as old_referral_code. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'old_referral_code')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('old_referral_code', 24)->nullable()->unique();
            });
        }
        DB::table('users')->whereNotNull('referral_code')->where('referral_code', 'not like', '%-%')->orderBy('id')->select('id', 'name', 'referral_code')->chunkById(200, function ($rows) {
            foreach ($rows as $u) {
                DB::table('users')->where('id', $u->id)->update(['old_referral_code' => $u->referral_code, 'referral_code' => Referrals::makeCode((string) $u->name)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('old_referral_code')->update(['referral_code' => DB::raw('old_referral_code')]);
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['old_referral_code']);
            $t->dropColumn('old_referral_code');
        });
    }
};
