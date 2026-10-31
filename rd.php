<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Settings;
$sd=Settings::get('searchData',[]); $pr=Settings::get('seoPairs',[]);
$top=fn($a,$n)=>array_slice(collect($a)->sortByDesc('impressions')->values()->all(),0,$n);
echo json_encode(['ready'=>App\Services\SearchConsole::ready(),'start'=>$sd['startDate']??null,'end'=>$sd['endDate']??null,'updated'=>$sd['updatedAt']??null,'pairsUpdated'=>$pr['updatedAt']??null,
'nq'=>count($sd['query']??[]),'np'=>count($sd['page']??[]),'npairs'=>count($pr['rows']??[]),
'query'=>$top($sd['query']??[],120),'page'=>$top($sd['page']??[],120),'pairs'=>$top($pr['rows']??[],250),
'proposals'=>DB::table('seo_proposals')->get(['kind','path','query','status','data_json'])->all(),'run'=>Settings::get('seoAgentRun',[])],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
