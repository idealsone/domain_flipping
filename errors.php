<?php
// Read-only: lists the newest unresolved entries of the owner Error Log.
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rows=Illuminate\Support\Facades\DB::table('error_logs')->whereNull('resolved_at')->orderByDesc('last_seen')->limit(25)->get(['level','source','occurrences','last_seen','location','url','message']);
echo 'unresolved: '.Illuminate\Support\Facades\DB::table('error_logs')->whereNull('resolved_at')->count().PHP_EOL;
foreach($rows as $r){echo $r->last_seen.' x'.$r->occurrences.' '.$r->level.'/'.$r->source.' '.$r->location.' '.$r->url.' :: '.mb_substr(preg_replace('/\s+/',' ',$r->message),0,220).PHP_EOL;}
