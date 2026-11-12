<?php
// Owner typed "do and complete" on 2026-11-12: Tara chat keeps 10 messages and at most 4 steps per answer (was 16 and 5). Runs once.
$h='/home/u280039231/domains/cvtara.com'; $W='/home/u280039231/cvtara_deploy';
if(is_file("$W/ops364.done")){echo file_get_contents("$W/ops364.log");exit;}
touch("$W/ops364.done");
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Settings;
ob_start();
$t=(array)Settings::get('tara',[]);
file_put_contents("$W/ops364_before.json",json_encode($t));
echo 'before '.json_encode(['historyMessages'=>$t['historyMessages']??null,'maxSteps'=>$t['maxSteps']??null]).PHP_EOL;
$t['historyMessages']=10; $t['maxSteps']=4;
Settings::put('tara',$t);
$s=App\Services\TaraChat::settings();
echo 'after '.json_encode(['enabled'=>$s['enabled'],'historyMessages'=>$s['historyMessages'],'maxSteps'=>$s['maxSteps'],'tools'=>count($s['tools'])]).PHP_EOL;
echo 'done '.date('c').PHP_EOL;
file_put_contents("$W/ops364.log",ob_get_clean());
echo file_get_contents("$W/ops364.log");
