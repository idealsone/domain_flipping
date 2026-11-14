<?php
// Creates the clearly marked QA accounts for the live test run. Prints the generated passwords/authenticator
// secret ONCE in the cron output (read privately through the Hostinger API). Idempotent: existing QA users are reset.
require __DIR__.'/qa_boot.php';
use Illuminate\Support\Facades\{DB,Hash,Crypt};
use App\Models\User;
$out=['at'=>date('c'),'build'=>(function()use($APP){foreach([$APP.'/BUILD_INFO.json',$APP.'/../BUILD_INFO.json',$APP.'/../public_html/BUILD_INFO.json'] as $f) if(is_file($f)) return json_decode(file_get_contents($f),true)['version']??null; return null;})(),'customers'=>[]];
foreach(qa_emails() as $e) DB::table('email_suppressions')->updateOrInsert(['email'=>$e],['reason'=>'owner','created_at'=>now(),'updated_at'=>now()]);
$mk=function(string $email,string $name,bool $admin=false) {
  $pw='Qa!'.bin2hex(random_bytes(9));
  $u=User::where('email',$email)->first() ?? new User(['name'=>$name,'email'=>$email]);
  $u->forceFill(['name'=>$name,'password'=>Hash::make($pw),'email_verified_at'=>now(),'blocked'=>false,'is_admin'=>$admin])->save();
  if(!$u->referral_code){ $u->forceFill(['referral_code'=>\App\Services\Referrals::makeCode($name)])->save(); }
  if(!$admin && !DB::table('profiles')->where('owner_id',$u->id)->exists())
    DB::table('profiles')->insert(['owner_id'=>$u->id,'name'=>$name,'experience_level'=>'fresher','profession'=>'Data Analyst','category'=>'Data & Analytics','onboarding_done'=>true,'marketing_consent'=>false,'created_at'=>now(),'updated_at'=>now()]);
  return [$u,$pw];
};
for($i=1;$i<=20;$i++){ [$u,$pw]=$mk(sprintf('%stester-%02d@%s',QA_PREFIX,$i,QA_DOMAIN),sprintf('QA Tester %02d',$i)); $out['customers'][]=['id'=>$u->id,'email'=>$u->email,'password'=>$pw,'referral'=>$u->referral_code]; }
// Testers 5, 6 and 7 get a free Job Hunt grant (provider manual, ₹0, not live) so paid tools can be tested
// without any payment. qa_cleanup.php deletes these ₹0 grants before erasing the accounts.
$plans=\App\Services\Settings::plans(); $out['grants']=[];
foreach([5,6,7,12,13,14] as $i){ $t=User::where('email',sprintf('%stester-%02d@%s',QA_PREFIX,$i,QA_DOMAIN))->first();
  if(DB::table('purchases')->where('owner_id',$t->id)->where('provider','manual')->exists()) continue;
  $plan=$plans['job-hunt']??null; if(!$plan){ $out['grants'][]='no job-hunt plan'; break; }
  $id=(string)\Illuminate\Support\Str::uuid(); $order='manual_'.str_replace('-','',$id);
  DB::table('purchases')->insert(['id'=>$id,'owner_id'=>$t->id,'plan'=>'job-hunt','amount_paise'=>0,'discount_paise'=>0,'provider'=>'manual','provider_order_id'=>$order,'is_live'=>false,'limits_json'=>json_encode($plan),'access_days'=>(int)($plan['accessDays']??30),'created_at'=>now(),'updated_at'=>now()]);
  \App\Services\Payments::capture(['order_id'=>$order,'id'=>'manual_qa'.$i,'amount'=>0,'currency'=>'INR','status'=>'captured'],'manual');
  DB::table('mail_outbox')->where('owner_id',$t->id)->where('status','queued')->delete();
  $out['grants'][]="tester-$i job-hunt"; }
[$o,$opw]=$mk(QA_PREFIX.'owner@'.QA_DOMAIN,'QA Test Owner',true);
$sec=\App\Services\Totp::newSecret();
DB::table('users')->where('id',$o->id)->update(['totp_secret'=>Crypt::encryptString($sec),'totp_enabled_at'=>now(),'totp_last_step'=>null]);
$out['owner']=['id'=>$o->id,'email'=>$o->email,'password'=>$opw,'totp'=>$sec,'paths'=>config('app.owner_paths')];
// a second QA owner so the scripted console check and the hands-on owner tester never share authenticator codes
[$o2,$opw2]=$mk(QA_PREFIX.'owner2@'.QA_DOMAIN,'QA Test Owner 2',true); $sec2=\App\Services\Totp::newSecret();
DB::table('users')->where('id',$o2->id)->update(['totp_secret'=>Crypt::encryptString($sec2),'totp_enabled_at'=>now(),'totp_last_step'=>null]);
$out['owner2']=['id'=>$o2->id,'email'=>$o2->email,'password'=>$opw2,'totp'=>$sec2,'paths'=>config('app.owner_paths')];
// B2B: a paused QA partner with one link; tester 10 is its manager.
$pid=DB::table('partners')->where('name','QA TEST Partner (delete me)')->value('id');
if(!$pid) $pid=DB::table('partners')->insertGetId(['name'=>'QA TEST Partner (delete me)','kind'=>'college','status'=>'active','contact_name'=>'QA','contact_email'=>QA_PREFIX.'partner@'.QA_DOMAIN,'plan_bps'=>3000,'addon_bps'=>4000,'agreement_ref'=>'QA-TEST','notes'=>'Live QA run, removed by qa_cleanup.php','created_by'=>$o->id,'created_at'=>now(),'updated_at'=>now()]);
DB::table('partners')->where('id',$pid)->update(['status'=>'active','agreement_ref'=>'QA-TEST']);
if(!DB::table('partner_links')->where('code','qa-test-partner')->exists()) DB::table('partner_links')->insert(['partner_id'=>$pid,'code'=>'qa-test-partner','campaign'=>'qa','label'=>'QA','active'=>true,'clicks'=>0,'created_at'=>now(),'updated_at'=>now()]);
$t10=User::where('email',sprintf('%stester-10@%s',QA_PREFIX,QA_DOMAIN))->first();
DB::table('partner_members')->updateOrInsert(['user_id'=>$t10->id],['partner_id'=>$pid,'role'=>'manager','created_at'=>now()]);
$t20=User::where('email',sprintf('%stester-20@%s',QA_PREFIX,QA_DOMAIN))->first();
DB::table('partner_members')->updateOrInsert(['user_id'=>$t20->id],['partner_id'=>$pid,'role'=>'manager','created_at'=>now()]);
$out['partner']=['id'=>$pid,'link'=>'qa-test-partner'];
$out['settings']=['emailVerification'=>\App\Services\EmailVerification::enabled(),'ownerCode'=>\App\Http\Controllers\AuthController::ownerCodeRequired(),'gateways'=>array_column(\App\Services\Payments::publicGateways(),'id')];
$out['errorLogBaseline']=DB::table('error_logs')->max('id');
echo "QAJSON ".json_encode($out)." QAEND\n";
