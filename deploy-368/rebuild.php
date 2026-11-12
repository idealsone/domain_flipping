<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
$t=[
 'pricing_paths'=>fn()=>count(array_filter(App\Services\SeoPages::paths(),fn($p)=>str_starts_with($p,'pricing'))),
 'pricing_products'=>fn()=>count(App\Services\SeoPages::find('pricing/')['offers']??[]),
 'sprint'=>fn()=>App\Services\SeoSprint::status()['active'],
 'updated_example'=>fn()=>App\Services\SeoContent::updatedAt('/resume-examples/'),
];
foreach($t as $k=>$f){try{echo "$k ok ".json_encode($f()).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,300).PHP_EOL;}}
