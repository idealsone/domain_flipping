<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// 3.74.0: seed the brand and product searches, show the design pictures, rebuild the ready-made pages
echo 'keywords '.json_encode(App\Services\SeoOps::syncKeywords()).PHP_EOL;
echo 'pictures resumes '.count(App\Services\TemplateImages::resumes()).' letters '.count(App\Services\TemplateImages::letters()).PHP_EOL;
echo 'whats new first: '.(App\Services\WhatsNew::all()[0]['id']??'?').PHP_EOL;
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
