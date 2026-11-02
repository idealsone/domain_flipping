<?php
// Read-only: is Search Console connected and is fast indexing in use? Prints no secrets.
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Settings;
$a=Settings::providers()['analytics']??[];
echo 'version '.json_decode(file_get_contents($h.'/cvtara_app/BUILD_INFO.json'),true)['version'].PHP_EOL;
echo 'property: '.($a['searchConsoleProperty']??'(empty)').PHP_EOL;
$j=json_decode($a['searchConsoleServiceAccount']??'null',true);
echo 'service account json: '.(is_array($j)?(($j['type']??'?').' '.($j['client_email']??'?')):'(empty or invalid)').PHP_EOL;
echo 'ga provider id: '.($a['measurementId']??'(empty)').' | content id: '.((Settings::get('integrations',[])['measurementId'])??'(empty)').PHP_EOL;
$s=Settings::get('searchData',[]);
echo 'searchData: '.($s['source']??'none').' '.($s['startDate']??'').'..'.($s['endDate']??'').' updated '.($s['updatedAt']??'-').' queries '.count($s['query']??[]).' pages '.count($s['page']??[]).PHP_EOL;
$f=App\Services\FastIndex::state();
echo 'fastIndex: key '.($f['key']?'yes':'no').' auto '.var_export($f['auto'],true).' google '.var_export($f['google'],true).' sent '.count($f['sent']).' googleSent '.count($f['googleSent']).' googleUsed '.$f['googleUsed'].' on '.$f['googleDay'].' sitemapAt '.($f['sitemapAt']??'never').PHP_EOL;
foreach(array_slice($f['log'],0,8) as $l) echo '  log '.$l['at'].' | '.$l['urls'].' | '.$l['indexnow'].' | '.$l['google'].PHP_EOL;
echo 'seo indexing '.var_export(Settings::get('seo')['searchIndexing'],true).PHP_EOL;
foreach(Illuminate\Support\Facades\DB::table('error_logs')->whereNull('resolved_at')->where(fn($q)=>$q->where('message','like','%Search Console%')->orWhere('message','like','%IndexNow%')->orWhere('message','like','%FastIndex%')->orWhere('location','like','%FastIndex%')->orWhere('location','like','%SearchConsole%'))->limit(5)->get() as $r) echo 'ERR '.$r->last_seen.' '.$r->location.' '.mb_substr($r->message,0,200).PHP_EOL;
if(($f['key']??'')!=='') echo 'keyfile '.((@file_get_contents('https://cvtara.com/'.$f['key'].'.txt'))===$f['key']?'ok':'not served'); echo PHP_EOL;
