<?php
// Owner typed "complete all the task" on 2026-11-12: privacy sentence, accept test-payment suggestions, approve starter interview questions and switch interview practice on.
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB; use App\Services\Settings;
$bk='/home/u280039231/cvtara_deploy/ops361_before.json';
if(is_file('/home/u280039231/cvtara_deploy/ops361.done')){echo file_get_contents('/home/u280039231/cvtara_deploy/ops361.log');exit;}
touch('/home/u280039231/cvtara_deploy/ops361.done');
ob_start();
$owner=App\Models\User::where('is_admin',true)->where('blocked',false)->orderBy('id')->first();
echo 'owner '.($owner?$owner->id:'NONE').PHP_EOL; if(!$owner){file_put_contents('/home/u280039231/cvtara_deploy/ops361.log',ob_get_clean());exit;}
file_put_contents($bk,json_encode(['legal'=>Settings::get('legal'),'interviews'=>Settings::get('interviews',[]),'marks'=>DB::table('payment_marks')->get(),'draftIds'=>DB::table('interview_questions')->where('status','draft')->pluck('id')]));
echo 'backup '.filesize($bk).PHP_EOL;
// 1 privacy
$legal=(array)Settings::get('legal',[]); $p=(string)($legal['privacy']??'');
$anchor='We keep your account and documents while your account is active.';
$add=' If you upload a file to build a resume, we keep a private copy of that original file for 90 days so you can download it again, then erase it automatically; you can delete it sooner under Account > Your data.';
if(str_contains($p,'private copy of that original file')) echo "privacy already has it\n";
elseif(str_contains($p,$anchor)){ $legal['privacy']=str_replace($anchor,$anchor.$add,$p); Settings::put('legal',$legal); echo "privacy updated\n"; }
else echo "privacy ANCHOR NOT FOUND, unchanged\n";
// 2 test payments
$rows=App\Services\TestPayments::rows(); $t=[];$r=[];
foreach($rows as $x){ if($x['mark']!=='')continue; if($x['suggested']==='test')$t[]=$x['id']; if($x['suggested']==='real')$r[]=$x['id']; echo "order {$x['id']} {$x['createdAt']} {$x['plan']} {$x['amountPaise']} {$x['suggested']} : {$x['reasons']}\n"; }
if($t) echo 'marked test '.App\Services\TestPayments::mark($owner,$t,'test','Accepted suggestion (owner: complete all)').PHP_EOL;
if($r) echo 'marked real '.App\Services\TestPayments::mark($owner,$r,'real','Accepted suggestion (owner: complete all)').PHP_EOL;
echo 'revenue orders now '.Settings::sinceStart(DB::table('purchases'),'created_at')->count().PHP_EOL;
// 3 interviews
$ids=DB::table('interview_questions')->where('status','draft')->pluck('id')->all();
echo 'approved '.App\Services\Interviews::approve($owner,$ids).' of '.count($ids).PHP_EOL;
Settings::put('interviews',array_merge((array)Settings::get('interviews',[]),['enabled'=>true]));
echo 'interviews '.json_encode(App\Services\Interviews::settings()).' available '.json_encode(App\Services\Interviews::available()).PHP_EOL;
$b=App\Services\PageCache::build(); echo 'built '.$b['built'].PHP_EOL;
echo 'done '.date('c').PHP_EOL;
file_put_contents('/home/u280039231/cvtara_deploy/ops361.log',ob_get_clean());
echo file_get_contents('/home/u280039231/cvtara_deploy/ops361.log');
