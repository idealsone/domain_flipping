<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
echo 'interview settings '.json_encode(App\Services\Interviews::settings()).PHP_EOL;
$S=Illuminate\Support\Facades\Schema::class;
echo 'columns '.json_encode([$S::hasColumn('profiles','interview_track'),$S::hasColumn('interview_sessions','release_at'),$S::hasColumn('interview_answers','custom_text'),$S::hasTable('interview_model_answers')]).PHP_EOL;
echo 'approved questions '.Illuminate\Support\Facades\DB::table('interview_questions')->where('status','approved')->count().PHP_EOL;
