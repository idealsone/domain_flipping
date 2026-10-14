<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('creator_applications',function(Blueprint $t){$t->id();$t->foreignId('owner_id')->unique()->constrained('users');$t->text('social_links_json');$t->string('audience',500);$t->string('status')->default('pending');$t->unsignedInteger('rate_bps')->nullable();$t->text('review_note')->nullable();$t->foreignId('reviewed_by')->nullable()->constrained('users');$t->timestamp('reviewed_at')->nullable();$t->timestamps();});
  Schema::create('payout_profiles',function(Blueprint $t){$t->foreignId('owner_id')->primary()->constrained('users');$t->text('details_encrypted');$t->string('destination_masked');$t->string('destination_hash',64)->index();$t->string('pan_hash',64)->nullable()->index();$t->string('status')->default('pending');$t->text('risk_flags_json');$t->text('review_note')->nullable();$t->string('beneficiary_id',100)->nullable();$t->foreignId('reviewed_by')->nullable()->constrained('users');$t->timestamp('reviewed_at')->nullable();$t->timestamp('eligible_at');$t->timestamps();});
  Schema::table('referral_commissions',function(Blueprint $t){$t->timestamp('available_at')->nullable()->index();});
  Schema::table('withdrawals',function(Blueprint $t){$t->string('provider')->default('manual');$t->string('provider_transfer_id',40)->nullable()->unique();$t->string('provider_status',80)->nullable();$t->timestamp('provider_checked_at')->nullable();$t->text('review_checklist_json')->nullable();$t->foreignId('approved_by')->nullable()->constrained('users');$t->timestamp('approved_at')->nullable();});
 }
 public function down():void {Schema::table('withdrawals',function(Blueprint $t){$t->dropConstrainedForeignId('approved_by');$t->dropColumn(['provider','provider_transfer_id','provider_status','provider_checked_at','review_checklist_json','approved_at']);});Schema::table('referral_commissions',fn(Blueprint $t)=>$t->dropColumn('available_at'));Schema::dropIfExists('payout_profiles');Schema::dropIfExists('creator_applications');}
};
