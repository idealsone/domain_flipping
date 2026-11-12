<?php
// Read-only: pulls Search Console performance (all available days, up to 16 months) and the stored URL Inspection / page-scan
// records, writes them to ~/cvtara_deploy/gsc_dump.txt once, then prints the next 60 KB chunk on each run. Prints no secrets.
$h='/home/u280039231/domains/cvtara.com'; $dir='/home/u280039231/cvtara_deploy'; $out=$dir.'/gsc_dump.txt'; $ctr=$dir.'/gsc_dump.ctr';
if(!is_file($out)){
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$L=[];$L[]='now '.now()->toIso8601String().' version '.json_decode(file_get_contents($h.'/cvtara_app/BUILD_INFO.json'),true)['version'];
$tok=App\Services\SearchConsole::token(App\Services\SearchConsole::READ);$prop=App\Services\SearchConsole::property();
$q=function($dims,$s,$e)use($tok,$prop){$rows=[];$start=0;do{$r=Illuminate\Support\Facades\Http::withToken($tok)->timeout(40)->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($prop).'/searchAnalytics/query',['startDate'=>$s,'endDate'=>$e,'dimensions'=>$dims,'rowLimit'=>25000,'startRow'=>$start,'dataState'=>'all']);if(!$r->successful()){return ['ERR '.$r->status().' '.mb_substr($r->body(),0,200)];}$b=$r->json('rows')??[];$rows=array_merge($rows,$b);$start+=25000;}while(count($b)===25000);return $rows;};
$end=now()->subDay()->toDateString();$s16=now()->subMonths(16)->addDays(2)->toDateString();
$fmt=fn($r)=>is_string($r)?$r:implode("\t",array_merge($r['keys'],[$r['clicks'],$r['impressions'],round($r['ctr']*100,2),round($r['position'],1)]));
foreach([['date'],['query'],['page'],['query','page'],['device'],['country']] as $d){$L[]='## '.implode('+',$d)." $s16..$end";foreach($q($d,$s16,$end) as $r)$L[]=$fmt($r);}
$a=now()->subDays(29)->toDateString();$b=now()->subDays(56)->toDateString();$c=now()->subDays(30)->toDateString();
$L[]="## query last28 $a..$end";foreach($q(['query'],$a,$end) as $r)$L[]=$fmt($r);
$L[]="## query prev28 $b..$c";foreach($q(['query'],$b,$c) as $r)$L[]=$fmt($r);
$L[]="## page last28 $a..$end";foreach($q(['page'],$a,$end) as $r)$L[]=$fmt($r);
$L[]="## page prev28 $b..$c";foreach($q(['page'],$b,$c) as $r)$L[]=$fmt($r);
$L[]='## seo_pages path|section|http|robots|canonical|verdict|coverage|indexing_state|google_canonical|words|inspected|inbound|title';
$rows=Illuminate\Support\Facades\DB::table('seo_pages')->orderBy('path')->get();
$in=[];foreach($rows as $r){foreach(array_unique(json_decode((string)$r->links_json,true)?:[]) as $t){if($t!==$r->path)$in[$t]=($in[$t]??0)+1;}}
foreach($rows as $r){$L[]=implode('|',[$r->path,$r->section,$r->http_status,$r->robots,$r->canonical,$r->verdict,$r->coverage,$r->indexing_state,$r->google_canonical,$r->words,$r->inspected_at?substr($r->inspected_at,0,10):'',$in[$r->path]??0,mb_substr($r->title,0,90)]);}
file_put_contents($out,implode("\n",$L)."\n");file_put_contents($ctr,'0');
}
$n=(int)@file_get_contents($ctr);$all=file_get_contents($out);$size=strlen($all);$chunk=60000;
echo 'CHUNK '.$n.' of '.ceil($size/$chunk).' bytes '.$size.PHP_EOL.substr($all,$n*$chunk,$chunk).PHP_EOL.'END CHUNK '.$n.PHP_EOL;
file_put_contents($ctr,(string)($n+1));
