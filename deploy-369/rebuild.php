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
 'sprint'=>fn()=>array_intersect_key(App\Services\SeoSprint::status(),['active'=>1,'day'=>1,'days'=>1,'until'=>1]),
 'sample_answers'=>fn()=>substr_count(json_encode(App\Services\SeoPages::find('interview-questions/hair-stylist/')['sections']),'sp-sample-answer'),
 'word_sections'=>fn()=>array_column(App\Services\SeoPages::find('resume-words/leading/')['sections'],0),
];
foreach($t as $k=>$f){try{echo "$k ok ".json_encode($f()).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,300).PHP_EOL;}}
