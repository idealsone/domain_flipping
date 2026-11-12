<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
try{
 echo 'before '.json_encode(DB::table('interview_questions')->groupBy('status')->selectRaw('status, count(*) c')->pluck('c','status')).PHP_EOL;
 echo 'seeded '.App\Services\Interviews::seed().PHP_EOL;
 // Owner approved the starter bank (card 12 Nov 10:50 UTC): approve starter questions still on their first wording only.
 $codes=array_column(json_decode(file_get_contents(resource_path('data/interview-bank.json')),true)['questions'],'code');
 $ids=DB::table('interview_questions as q')->join('interview_question_versions as v','v.id','=','q.current_version_id')->where('q.status','draft')->where('v.version',1)->whereIn('q.code',$codes)->pluck('q.id')->all();
 $owner=App\Models\User::where('is_admin',true)->where('blocked',false)->orderBy('id')->first();
 echo 'approved '.App\Services\Interviews::approve($owner,$ids).' (bank file '.count($codes).')'.PHP_EOL;
 echo 'after '.json_encode(DB::table('interview_questions')->groupBy('status')->selectRaw('status, count(*) c')->pluck('c','status')).PHP_EOL;
 echo 'template '.strlen(App\Services\Interviews::importTemplate()).' settings '.json_encode(App\Services\Interviews::settings()).PHP_EOL;
}catch(Throwable $e){echo 'ERR '.substr($e->getMessage(),0,300).PHP_EOL;}
$log=$h.'/cvtara_app/storage/logs/laravel.log';
$n=0;foreach(new SplFileObject($log) as $line){ if(str_starts_with($line,'[2026-11-12')){ $last[]=substr($line,0,230); } }
echo "today's log headers (last 8):\n".implode("\n",array_slice($last??[],-8)).PHP_EOL;
