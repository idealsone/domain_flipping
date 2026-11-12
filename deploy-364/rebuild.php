<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=App\Services\PageCache::build();
echo 'built '.$r['built'].' skipped '.count($r['skipped']).PHP_EOL;
$Sc='Illuminate\Support\Facades\Schema';
$t=[
 'cache_cols'=>fn()=>$Sc::hasColumn('ai_usage','cache_read_tokens') && $Sc::hasColumn('ai_usage','cache_write_tokens'),
 'models'=>fn()=>App\Services\AiGateway::models(),
 'tiers'=>fn()=>[App\Services\AiGateway::tier('customer:tara_chat'),App\Services\AiGateway::tier('seo'),App\Services\AiGateway::tier('customer:expert_review'),App\Services\AiGateway::effort('customer:tara_chat')],
 'tara_settings'=>fn()=>array_intersect_key(App\Services\TaraChat::settings(),array_flip(['enabled','model','historyMessages','maxSteps'])),
 'owner_settings'=>fn()=>array_intersect_key(App\Services\TaraOwner::settings(),array_flip(['model','historyMessages','maxSteps'])),
 'ai_on'=>fn()=>App\Services\TaraAI::enabled(),
 'cost_7d'=>fn()=>Illuminate\Support\Facades\DB::table('ai_usage')->where('created_at','>=',now()->subDays(7))->selectRaw('purpose, model, count(*) n, sum(input_tokens) i, sum(output_tokens) o, sum(cost_paise) p')->groupBy('purpose','model')->orderByDesc('p')->limit(12)->get(),
];
foreach($t as $k=>$f){try{echo "$k ok ".json_encode($f()).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,300).PHP_EOL;}}
