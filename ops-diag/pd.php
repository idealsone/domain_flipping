<?php
// Read-only diagnostic: recent purchases and plan names. Prints no secrets.
$base='/home/u280039231/domains/cvtara.com/cvtara_app';
require $base.'/vendor/autoload.php';
$app=require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB; use App\Services\Settings;
$m=fn($e)=>substr($e,0,3).'***@'.explode('@',$e)[1];
echo "BUILD ".@file_get_contents($base.'/BUILD_INFO')."\n";
$plans=Settings::plans();
foreach($plans as $k=>$p) echo "PLAN $k name=".($p['name']??'')." type=".($p['type']??'')." sale=".json_encode($p['sale']??null)." days=".($p['accessDays']??'')." res=".($p['savedResumes']??'')." let=".($p['savedLetters']??'')." ats=".json_encode($p['atsScans']??null)." rank=".($p['rank']??'')."\n";
$raw=(array)Settings::get('plans',[]); echo "SAVED_PLAN_KEYS ".implode(',',array_keys($raw))."\n";
$pp=Settings::providers()['payments']??[]; echo "GATEWAY provider=".($pp['provider']??'?')." phonepeMode=".($pp['phonepeMode']??'?')." rzp=".(isset($pp['razorpayKeyId'])?substr($pp['razorpayKeyId'],0,9):'none')."\n";
$rows=DB::table('purchases')->where('created_at','>=',now()->subDays(4))->orderBy('created_at')->get();
foreach($rows as $r){$l=json_decode($r->limits_json,true)?:[];$u=DB::table('users')->find($r->owner_id);
echo "PUR ".$r->created_at." id=".$r->id." user=".$r->owner_id."(".($u?$m($u->email):'?').($u&&$u->is_admin?' ADMIN':'').") plan=".$r->plan." lname=".($l['name']??'')." ltype=".($l['type']??'')." rank=".($l['rank']??'')." amt=".$r->amount_paise." prov=".$r->provider." live=".$r->is_live." status=".$r->status." ref=".($r->payment_reference?'yes':'no')." until=".$r->access_until." days=".$r->access_days."\n";}
foreach($rows->pluck('owner_id')->unique() as $id){$a=Settings::access((int)$id);echo "ACCESS user=$id plan=".$a['plan']." paid=".json_encode($a['paid'])." until=".$a['accessUntil']." nameLookup=".($plans[$a['plan']]['name']??'MISSING->Free')." limitsName=".($a['limits']['name']??'')."\n";}
foreach(DB::table('error_logs')->where('created_at','>=',now()->subDays(2))->orderByDesc('created_at')->limit(25)->get() as $e) echo "ERR ".$e->created_at." ".substr(preg_replace('/\s+/',' ',json_encode($e)),0,400)."\n";
foreach(DB::table('activity_events')->where('created_at','>=',now()->subDays(2))->whereIn('event',['checkout_started','purchase_verified','manual_grant'])->orderByDesc('created_at')->limit(20)->get() as $e) echo "EVT ".$e->created_at." ".$e->owner_id." ".$e->event." ".$e->reference."\n";
