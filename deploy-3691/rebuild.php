<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
$pro=App\Services\Settings::plans()['pro']; echo 'pro '.json_encode([$pro['savedResumes'],$pro['savedLetters'],$pro['accessDays'],$pro['perks'][0]??'']).PHP_EOL;
echo 'promos '.json_encode(array_map(fn($c)=>[($c['name']??''),($c['mode']??'')],App\Services\Promos::all())).PHP_EOL;
