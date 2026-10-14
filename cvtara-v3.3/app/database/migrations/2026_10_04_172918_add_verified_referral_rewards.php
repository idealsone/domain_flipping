<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('referral_identities',function(Blueprint $t){$t->foreignId('owner_id')->primary()->constrained('users');$t->string('phone_hash',64)->unique();$t->string('phone_masked',25);$t->string('device_hash',64)->nullable()->index();$t->string('ip_hash',64)->nullable()->index();$t->string('status',20)->default('verified');$t->text('review_note')->nullable();$t->timestamp('verified_at');$t->timestamps();});
  Schema::create('referral_rewards',function(Blueprint $t){$t->id();$t->foreignId('owner_id')->constrained('users');$t->unsignedTinyInteger('milestone');$t->unsignedSmallInteger('exports_remaining')->default(0);$t->timestamps();$t->unique(['owner_id','milestone']);});
 }
 public function down():void {Schema::dropIfExists('referral_rewards');Schema::dropIfExists('referral_identities');}
};
