<?php
// Shared bootstrap for the live QA helper scripts. Run from a temporary account cron only.
$APP=getenv('QA_APP')?:'/home/u280039231/domains/cvtara.com/cvtara_app';
require $APP.'/vendor/autoload.php';
$app=require $APP.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
const QA_DOMAIN='example.com';           // reserved domain: never delivers mail
const QA_PREFIX='cvtara-qa-';            // every QA address starts with this
function qa_emails(): array {
  $e=[];
  for($i=1;$i<=20;$i++) $e[]=sprintf('%stester-%02d@%s',QA_PREFIX,$i,QA_DOMAIN);
  for($i=1;$i<=5;$i++)  $e[]=sprintf('%ssignup-%02d@%s',QA_PREFIX,$i,QA_DOMAIN);
  $e[]=QA_PREFIX.'owner@'.QA_DOMAIN; $e[]=QA_PREFIX.'owner2@'.QA_DOMAIN; $e[]=QA_PREFIX.'partner-apply@'.QA_DOMAIN;
  return $e;
}
function qa_users(){ return DB::table('users')->where('email','like',QA_PREFIX.'%@'.QA_DOMAIN)->get(); }
