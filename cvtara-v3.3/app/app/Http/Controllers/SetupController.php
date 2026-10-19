<?php
namespace App\Http\Controllers;
use App\Services\{Settings,AccountSecurity,MailAutomation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Artisan};
use Illuminate\Support\Str;
class SetupController extends Controller {
 public function run(Request $r,string $path) {
  abort_unless($r->user()?->is_admin&&!$r->user()->blocked,403);$ok=fn($d=[])=>response()->json(['ok'=>true,...$d]);
  if($path==='status'&&$r->isMethod('GET')){$p=Settings::providers();$h=Settings::get('cronHeartbeat',[]);$recent=isset($h['at'])&&strtotime($h['at'])>now()->subMinutes(5)->timestamp;$legal=Settings::get('legal');return $ok(['domain'=>config('app.url'),'cronCommand'=>PHP_BINARY.' '.base_path('private-cron.php'),'webhooks'=>['cashfree'=>url('/webhooks/cashfree'),'razorpay'=>url('/webhooks/razorpay')],'checks'=>['https'=>str_starts_with(config('app.url'),'https://'),'privateFolder'=>!str_starts_with(base_path(),public_path().DIRECTORY_SEPARATOR),'emailConfigured'=>!empty($p['email']['fromEmail']),'scheduler'=>$recent,'cashfreeConfigured'=>!empty($p['payments']['cashfreeClientId'])&&!empty($p['payments']['cashfreeClientSecret']),'cashfreeWebhookReceived'=>(bool)Settings::get('cashfreeWebhookSeen'),'payoutPolicy'=>(bool)Settings::get('payoutPolicyReviewed',false)],'heartbeat'=>$h,'operator'=>Settings::get('operator',[])]);}
  abort_unless($r->isMethod('POST'),405);
  if($path==='business'){$v=$r->validate(['legalName'=>'required|string|max:120','supportEmail'=>'required|email|max:190','supportPhone'=>'required|string|max:30','address'=>'required|string|min:10|max:500']);Settings::put('operator',$v);$legal=Settings::get('legal');foreach(['contactEmail'=>$v['supportEmail'],'contactPhone'=>$v['supportPhone']] as $k=>$value)if(array_key_exists($k,$legal))$legal[$k]=$value;Settings::put('legal',$legal);Settings::audit($r->user()->id,'business_setup_saved');return $ok();}
  if($path==='cache'){Artisan::call('view:clear');Artisan::call('config:clear');Artisan::call('route:clear');Settings::audit($r->user()->id,'compiled_cache_cleared');return $ok();}
  if($path==='email-test'){AccountSecurity::send($r);$result=MailAutomation::process();return $ok(['delivery'=>$result]);}
  AccountSecurity::requireRecent($r);
  if($path==='payout-policy'){$v=$r->validate(['identityProcess'=>'required|string|min:20|max:2000','taxProcess'=>'required|string|min:20|max:2000','retentionDays'=>'required|integer|min:1|max:3650','reviewer'=>'required|string|min:3|max:120','confirmed'=>'accepted']);Settings::put('payoutPolicyReviewed',[...$v,'at'=>now()->toIso8601String(),'owner'=>$r->user()->id]);Settings::audit($r->user()->id,'payout_policy_recorded');return $ok();}
  abort(404);
 }
}
