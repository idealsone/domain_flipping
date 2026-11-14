<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// 3.76.0: check the Complete Job Kit is on sale, then rebuild the ready-made pages (pricing leads with Job Hunt)
$k=App\Services\Settings::plans()['complete-kit']??[];
echo 'kit sale '.json_encode($k['sale']??null).' price '.($k['price']??'?').' rank '.($k['rank']??'?').PHP_EOL;
echo 'whats new first: '.(App\Services\WhatsNew::all()[0]['id']??'?').PHP_EOL;
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
