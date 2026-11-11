<?php
// Read-only: Google index status (URL Inspection results stored in seo_pages), Search Console data, IndexNow/fast-index state. Prints no secrets.
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Settings; use Illuminate\Support\Facades\DB;
echo 'now '.now()->toIso8601String().' version '.json_decode(file_get_contents($h.'/cvtara_app/BUILD_INFO.json'),true)['version'].PHP_EOL;
$t=DB::table('seo_pages');
echo 'seo_pages total '.(clone $t)->count().' | http200 '.(clone $t)->where('http_status',200)->count().' | inspected '.(clone $t)->whereNotNull('inspected_at')->count().' | inspect errors '.(clone $t)->where('inspect_error','!=','')->count().' | last inspected '.(clone $t)->max('inspected_at').' | index requested '.(clone $t)->whereNotNull('index_requested_at')->count().PHP_EOL;
foreach(DB::table('seo_pages')->whereNotNull('inspected_at')->selectRaw('verdict, coverage, count(*) n')->groupBy('verdict','coverage')->orderByDesc('n')->get() as $r) echo '  '.$r->n.' | '.$r->verdict.' | '.$r->coverage.PHP_EOL;
foreach(DB::table('seo_pages')->where('http_status',200)->selectRaw("section, count(*) n, sum(verdict='PASS') pass, sum(inspected_at is not null) insp")->groupBy('section')->orderByDesc('n')->get() as $r) echo '  section '.$r->section.': '.$r->n.' pages, '.$r->insp.' inspected, '.$r->pass.' indexed'.PHP_EOL;
foreach(DB::table('seo_pages')->where('verdict','PASS')->orderBy('path')->limit(60)->pluck('path') as $p) echo '  indexed '.$p.PHP_EOL;
foreach(DB::table('seo_pages')->where('inspect_error','!=','')->limit(3)->pluck('inspect_error') as $e) echo '  inspErr '.mb_substr($e,0,160).PHP_EOL;
$s=Settings::get('searchData',[]);
echo 'searchData: '.($s['source']??'none').' '.($s['startDate']??'').'..'.($s['endDate']??'').' updated '.($s['updatedAt']??'-').' queries '.count($s['query']??[]).' pages '.count($s['page']??[]).PHP_EOL;
$imp=0;$clk=0; foreach(($s['page']??[]) as $p){$imp+=(int)($p['impressions']??0);$clk+=(int)($p['clicks']??0);} echo '  page impressions '.$imp.' clicks '.$clk.PHP_EOL;
$f=App\Services\FastIndex::status();
echo 'fastIndex: sitemap pages '.$f['pages'].' | indexnow waiting '.$f['waiting'].' | google api waiting '.$f['googleWaiting'].' | auto '.var_export($f['auto'],true).' google '.var_export($f['google'],true).' | googleUsed today '.$f['googleUsed'].'/'.$f['googleLimit'].' | sitemapAt '.($f['sitemapAt']??'never').' | GSC ready '.var_export($f['searchConsole'],true).' | indexing '.var_export($f['indexing'],true).PHP_EOL;
$st=App\Services\FastIndex::state(); echo '  indexnow sent total '.count($st['sent']).' | google api sent total '.count($st['googleSent']).PHP_EOL;
foreach(array_slice($f['log'],0,12) as $l) echo '  log '.$l['at'].' | '.$l['urls'].' | '.$l['indexnow'].' | '.mb_substr((string)$l['google'],0,160).PHP_EOL;
if(($f['key']??'')!=='') echo 'keyfile '.((@file_get_contents($f['keyUrl']))===$f['key']?'ok':'not served').PHP_EOL;
foreach(DB::table('error_logs')->whereNull('resolved_at')->where(fn($q)=>$q->where('message','like','%Search Console%')->orWhere('message','like','%IndexNow%')->orWhere('message','like','%inspect%')->orWhere('location','like','%FastIndex%')->orWhere('location','like','%SeoOps%'))->limit(5)->get() as $r) echo 'ERR '.$r->last_seen.' '.$r->location.' '.mb_substr($r->message,0,200).PHP_EOL;
