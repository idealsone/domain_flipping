<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
try{
 $c=App\Services\TemplateLibrary::coverage();
 foreach($c['clusters'] as $x) echo 'cov '.$x['cluster'].' r'.$x['resumes'].' l'.$x['letters'].PHP_EOL;
 foreach($c['levels'] as $x) echo 'lvl '.$x['level'].' r'.$x['resumes'].' l'.$x['letters'].PHP_EOL;
 echo 'categories '.count(App\Services\JobFields::CATEGORIES).PHP_EOL;
 $wn=App\Services\WhatsNew::all(); echo 'whatsnew '.count($wn).PHP_EOL;
 $p=DB::table('resume_drafts')->where('is_primary',true)->value('owner_id'); if($p){ $j=App\Services\ResumeJourney::for((int)$p); echo 'journey ok v1 '.($j['v1']['score']??'-').' v3 '.count($j['v3']??[]).PHP_EOL; }
 $rv=App\Services\Reviews::published(); echo 'reviews published '.count($rv).' verified '.count(array_filter($rv,fn($x)=>$x['verified'])).PHP_EOL;
}catch(Throwable $e){echo 'ERR '.substr($e->getMessage(),0,300).PHP_EOL;}
$log=$h.'/cvtara_app/storage/logs/laravel.log';
foreach(new SplFileObject($log) as $line){ if(str_starts_with($line,'[2026-11-12')){ $last[]=substr($line,0,230); } }
echo "today's log headers (last 8):\n".implode("\n",array_slice($last??[],-8)).PHP_EOL;
