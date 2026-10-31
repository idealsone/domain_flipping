<?php
// Read-only: prints the owner Error Log (unresolved, grouped) and a summary of server log files. Changes nothing.
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$DB='Illuminate\Support\Facades\DB';
echo "== version ".@file_get_contents($h.'/cvtara_app/BUILD_INFO.json')."\n";
echo "== error_logs total ".$DB::table('error_logs')->count()." unresolved ".$DB::table('error_logs')->whereNull('resolved_at')->count()."\n";
$rows=$DB::table('error_logs')->whereNull('resolved_at')->orderByDesc('last_seen')->limit(150)->get();
foreach($rows as $r){echo "#$r->id $r->first_seen..$r->last_seen x$r->occurrences $r->level/$r->source @$r->location ".($r->url?:'-')." :: ".mb_substr(preg_replace('/\s+/',' ',$r->message),0,400).($r->context?' ctx='.mb_substr($r->context,0,200):'')."\n";}
echo "== resolved in last 14 days (top 30 by count)\n";
foreach($DB::table('error_logs')->whereNotNull('resolved_at')->where('last_seen','>',now()->subDays(14))->orderByDesc('occurrences')->limit(30)->get() as $r){echo "#$r->id x$r->occurrences $r->source @$r->location :: ".mb_substr(preg_replace('/\s+/',' ',$r->message),0,200)."\n";}
$files=array_merge(glob($h.'/cvtara_app/storage/logs/*.log')?:[],glob($h.'/public_html/error_log')?:[],glob($h.'/cvtara_app/error_log')?:[],glob('/home/u280039231/logs/*')?:[],glob($h.'/logs/*')?:[]);
foreach($files as $f){ if(!is_file($f)) continue; $sz=filesize($f); echo "== file $f size $sz mtime ".date('c',filemtime($f))."\n";
  $fh=fopen($f,'r'); if($sz>3000000) fseek($fh,$sz-3000000); $g=[]; $first=[];$last=[];
  while(($l=fgets($fh))!==false){ if(!preg_match('/^\[(\d{4}-\d\d-\d\d[^\]]*)\]\s*(.*)$/',$l,$m)) continue; $msg=preg_replace(['/\d+/','/\s+/'],['#',' '],mb_substr($m[2],0,260)); $g[$msg]=($g[$msg]??0)+1; $first[$msg]??=$m[1]; $last[$msg]=$m[1]; }
  fclose($fh); arsort($g); $i=0; foreach($g as $k=>$c){ if($i++>=40) break; echo "  x$c {$first[$k]} .. {$last[$k]} :: $k\n"; }
}
echo "== done ".date('c')."\n";
