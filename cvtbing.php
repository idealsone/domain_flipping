<?php
// Read-only: asks Bing Webmaster Tools for fresh numbers with the saved key and prints the Search engines box data. Never prints the key.
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app=require $h.'/cvtara_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'now '.now()->toIso8601String().' version '.json_decode(file_get_contents($h.'/cvtara_app/BUILD_INFO.json'),true)['version'].PHP_EOL;
$k=App\Services\IndexStatus::bingKey();
echo 'bing key saved: '.($k!==''?'yes ('.strlen($k).' chars)':'no').PHP_EOL;
$a=App\Services\IndexStatus::all(true);
$s=json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if($k!=='') $s=str_replace($k,'[key]',$s);
echo $s.PHP_EOL;
if($k!==''){
  $base='https://ssl.bing.com/webmaster/api.svc/json/';
  foreach(['GetUserSites'=>[],'GetUrlSubmissionQuota'=>['siteUrl'=>'https://cvtara.com/']] as $m=>$q){
    try{$r=Illuminate\Support\Facades\Http::timeout(12)->acceptJson()->get($base.$m,$q+['apikey'=>$k]);echo $m.' '.$r->status().' '.str_replace($k,'[key]',mb_substr($r->body(),0,700)).PHP_EOL;}catch(\Throwable $e){echo $m.' ERR '.$e->getMessage().PHP_EOL;}
  }
}
