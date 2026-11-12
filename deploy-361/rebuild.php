<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
$D='Illuminate\Support\Facades\DB'; $Sc='Illuminate\Support\Facades\Schema';
$t=[
 'mode_col'=>fn()=>$Sc::hasColumn('interview_sessions','mode'),
 'originals'=>fn()=>App\Services\ResumeOriginals::ready(),
 'test_payments'=>fn()=>App\Services\TestPayments::summary(App\Services\TestPayments::rows()),
 'since_start'=>fn()=>App\Services\Settings::sinceStart($D::table('purchases'),'created_at')->count(),
 'interviews'=>fn()=>App\Services\Interviews::settings(),
 'index_status'=>fn()=>class_exists(App\Services\IndexStatus::class),
];
foreach($t as $k=>$f){try{echo "$k ok ".json_encode($f()).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,300).PHP_EOL;}}
