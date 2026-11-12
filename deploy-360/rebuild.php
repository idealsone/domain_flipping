<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
$t=[
 'knowledge'=>fn()=>count(App\Services\KnowledgeSync::status()['agents']),
 'cadence'=>fn()=>count(App\Services\AgentCadence::overview()['reports']??[]),
 'templates'=>fn()=>count(App\Services\TemplateLibrary::coverage()),
 'backlog'=>fn()=>Illuminate\Support\Facades\DB::table('seo_keywords')->whereNotNull('cluster')->count(),
 'brand'=>fn()=>Illuminate\Support\Facades\DB::table('brand_profiles')->count(),
 'questions'=>fn()=>Illuminate\Support\Facades\DB::table('interview_questions')->count(),
 'tpl_rows'=>fn()=>Illuminate\Support\Facades\DB::table('template_library')->count(),
];
foreach($t as $k=>$f){try{echo "$k ok ".json_encode($f()).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,300).PHP_EOL;}}
