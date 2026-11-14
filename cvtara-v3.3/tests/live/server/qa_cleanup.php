<?php
// Removes everything the live QA run created: QA users (via AccountEraser), their unpaid orders, the QA partner,
// QA partner applications and contact-form messages. Leaves the email suppressions in place (harmless, blocks mail).
require __DIR__.'/qa_boot.php';
use Illuminate\Support\Facades\{DB,Schema};
$log=[];
$users=qa_users();
foreach($users as $u){
  if(Schema::hasTable('purchases')){ $paid=DB::table('purchases')->where('owner_id',$u->id)->whereNotIn('status',['created','failed'])->count(); $log[]="user {$u->id} paid orders: $paid";
    DB::table('purchases')->where('owner_id',$u->id)->whereIn('status',['created','failed'])->delete();
    // the ₹0 manual QA grants from qa_setup.php are not real money: remove them instead of keeping them for accounts
    $g=DB::table('purchases')->where('owner_id',$u->id)->where('provider','manual')->where('amount_paise',0)->pluck('id');
    foreach(['referral_commissions'=>'purchase_id','partner_commissions'=>'purchase_id'] as $t=>$c) if(Schema::hasTable($t)&&Schema::hasColumn($t,$c)) DB::table($t)->whereIn($c,$g)->delete();
    DB::table('purchases')->whereIn('id',$g)->delete(); $log[]="user {$u->id} QA grants removed: ".count($g); }
  DB::table('partner_members')->where('user_id',$u->id)->delete();
  if(Schema::hasTable('user_roles')) DB::table('user_roles')->where('user_id',$u->id)->delete();
  if($u->is_admin) DB::table('users')->where('id',$u->id)->update(['is_admin'=>false,'totp_secret'=>null,'totp_enabled_at'=>null]);
  try{ App\Services\AccountEraser::erase((int)$u->id); $log[]="erased {$u->id} {$u->email}"; }catch(Throwable $e){ $log[]="ERASE FAILED {$u->id}: ".$e->getMessage(); DB::table('users')->where('id',$u->id)->update(['blocked'=>true]); }
}
$pids=DB::table('partners')->where('name','like','QA TEST%')->pluck('id');
foreach(['partner_commissions','partner_members','partner_links'] as $t) if(Schema::hasTable($t)) DB::table($t)->whereIn('partner_id',$pids)->delete();
$log[]='partners removed: '.DB::table('partners')->whereIn('id',$pids)->delete();
$log[]='partner applications removed: '.DB::table('partner_applications')->where('email','like',QA_PREFIX.'%')->delete();
if(Schema::hasTable('support_reports')){ $ids=DB::table('support_reports')->where('email','like',QA_PREFIX.'%')->orWhere('message','like','QA TEST%')->pluck('id');
  if(Schema::hasTable('support_attachments')&&Schema::hasColumn('support_attachments','report_id')) DB::table('support_attachments')->whereIn('report_id',$ids)->delete();
  if(Schema::hasTable('support_replies')&&Schema::hasColumn('support_replies','report_id')) DB::table('support_replies')->whereIn('report_id',$ids)->delete();
  $log[]='support reports removed: '.DB::table('support_reports')->whereIn('id',$ids)->delete(); }
foreach(['contact_messages','support_messages'] as $t) if(Schema::hasTable($t)&&Schema::hasColumn($t,'email')) $log[]="$t removed: ".DB::table($t)->where('email','like',QA_PREFIX.'%')->delete();
$log[]='mail_outbox QA rows removed: '.DB::table('mail_outbox')->where('recipient','like',QA_PREFIX.'%')->delete();
$log[]='QA users left: '.qa_users()->count();
echo implode("\n",$log)."\n== done ".date('c')."\n";
