<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/vendor/autoload.php';$app=require __DIR__.'/bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();exit(Illuminate\Support\Facades\Artisan::call('cvtara:cron'));
