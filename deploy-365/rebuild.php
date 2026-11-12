<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
$t=[
 'brand_schema'=>fn()=>count(App\Http\Controllers\ContentController::brandSchema('https://cvtara.com')),
 'playbook'=>fn()=>count(App\Services\SeoBacklog::playbook()['pageTemplate']??[]),
 'backlog_added'=>fn()=>App\Services\SeoBacklog::load(),
 'backlog_clusters'=>fn()=>count(App\Services\SeoBacklog::summary()),
 'link_plan'=>fn()=>count(App\Services\SeoBacklog::linkPlan()),
];
foreach($t as $k=>$f){try{echo "$k ok ".json_encode($f()).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,300).PHP_EOL;}}
