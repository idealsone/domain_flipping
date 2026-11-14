<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
// Owner decision 14 Nov 2026 ("Open it at deploy"): every question pattern approved at deploy.
$owner=App\Models\User::where('is_admin',true)->orderBy('id')->first();
echo 'patterns before '.json_encode(DB::table('interview_bank_patterns')->groupBy('status')->selectRaw('status, count(*) c')->pluck('c','status')).PHP_EOL;
if($owner){ echo 'approved now '.App\Services\InterviewBank::setStatus($owner,'*','approved').PHP_EOL; } else { echo 'NO OWNER USER, patterns left as drafts'.PHP_EOL; }
App\Services\InterviewBank::flush();
echo 'bank '.json_encode(array_diff_key(App\Services\InterviewBank::stats(),['categories'=>1])).PHP_EOL;
echo 'roles '.DB::table('career_occupations')->where('active',true)->count().PHP_EOL;
$t=collect(App\Services\InterviewBank::forRole('telecaller','1-3'));
echo 'telecaller 1-3: '.$t->count().' e.g. '.$t->first()->text.PHP_EOL;
echo 'whats new first: '.(App\Services\WhatsNew::all()[0]['id']??'?').PHP_EOL;
App\Services\PageCache::purge();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
