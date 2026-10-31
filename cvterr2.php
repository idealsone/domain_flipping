<?php
// Read-only: deeper summary of laravel.log. Changes nothing.
$f='/home/u280039231/domains/cvtara.com/cvtara_app/storage/logs/laravel.log';
$sz=filesize($f); $fh=fopen($f,'r'); if($sz>9000000) fseek($fh,$sz-9000000);
$g=[];$first=[];$last=[];$days=[];$sample=null;$cur=null;$curlines=0;$traces=[];
while(($l=fgets($fh))!==false){
  if(preg_match('/^\[(\d{4}-\d\d-\d\d) (\d\d):[^\]]*\]\s*(\S+)\s*(.*)$/',$l,$m)){
    $msg=preg_replace(['/\b[A-Za-z0-9]{24,}\b/','/\d+/','/\s+/'],['TOK','#',' '],mb_substr($m[4],0,180));
    $k=$m[3].' '.$msg; $g[$k]=($g[$k]??0)+1; $first[$k]??=$m[1]; $last[$k]=$m[1].' '.$m[2]; $days[$k][$m[1]]=($days[$k][$m[1]]??0)+1;
    $cur=$k; $curlines=0; if(!isset($traces[$k])) $traces[$k]=[];
    continue;
  }
  if($cur!==null && count($traces[$cur])<14 && $curlines<400 && preg_match('/^#\d+ (.*)$/',trim($l),$t)){ if(!preg_match('#/vendor/#',$t[1])||count($traces[$cur])<3) $traces[$cur][]=mb_substr($t[1],0,170); }
  $curlines++;
}
arsort($g); foreach($g as $k=>$c){ echo "x$c {$first[$k]}..{$last[$k]} :: $k\n   days: ".json_encode($days[$k])."\n"; foreach(($traces[$k]??[]) as $t) echo "     $t\n"; }
echo "== cron/php errors\n";
foreach(['/home/u280039231/domains/cvtara.com/public_html/error_log','/home/u280039231/domains/cvtara.com/cvtara_app/error_log','/home/u280039231/error_log'] as $e){ if(is_file($e)){ echo "$e ".filesize($e)."\n"; $t=file($e); foreach(array_slice($t,-15) as $x) echo '  '.mb_substr(trim($x),0,250)."\n"; } }
echo "== .env db host: "; foreach(file('/home/u280039231/domains/cvtara.com/cvtara_app/.env') as $x){ if(preg_match('/^(DB_HOST|DB_SOCKET|SESSION_DRIVER|CACHE_STORE|QUEUE_CONNECTION|DB_PERSISTENT)=/',$x)) echo trim($x).' '; } echo "\n";
echo "php ".PHP_VERSION." max_connections? ";
try{ $app=null; require '/home/u280039231/domains/cvtara.com/cvtara_app/vendor/autoload.php'; $app=require '/home/u280039231/domains/cvtara.com/cvtara_app/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $D='Illuminate\Support\Facades\DB'; foreach(['max_connections','max_user_connections','wait_timeout'] as $v){ $r=$D::select("show variables like '$v'"); echo $v.'='.($r[0]->Value??'?').' '; } $r=$D::select("show status like 'Max_used_connections'"); echo 'max_used='.($r[0]->Value??'?'); $r=$D::select("show status like 'Threads_connected'"); echo ' now='.($r[0]->Value??'?')."\n"; echo "sessions rows ".$D::table('sessions')->count()." cache rows ".$D::table('cache')->count()."\n"; }catch(Throwable $e){ echo get_class($e).' '.$e->getMessage()."\n"; }
echo "== done ".date('c')."\n";
