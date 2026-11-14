<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'whats new first: '.(App\Services\WhatsNew::all()[0]['id']??'?').PHP_EOL;
$paths=App\Services\SeoPages::paths(); $with=0;
foreach($paths as $p){ if(App\Services\SeoPages::picture('/'.$p)) $with++; }
echo 'seo pages '.count($paths).' with own picture '.$with.PHP_EOL;
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
