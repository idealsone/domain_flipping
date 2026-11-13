<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
try{ $s=App\Services\Settings::get('site'); echo 'hero '.json_encode(['t'=>$s['heroTitle']??null,'c1'=>$s['ctaPrimary']??null,'c2'=>$s['ctaSecondary']??null]).PHP_EOL; }catch(Throwable $e){ echo 'hero ERR '.substr($e->getMessage(),0,200).PHP_EOL; }
