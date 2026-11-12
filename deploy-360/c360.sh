#!/bin/bash
# Read-only check after the 3.60.0 deploy: recent errors and the first scheduler work. Changes nothing.
H=/home/u280039231/domains/cvtara.com; cd $H/cvtara_app
echo "== $(date -u)"; grep -a -o '^\[2026-11-12[^]]*\] [a-z]*\.ERROR: .\{0,260\}' storage/logs/laravel.log | tail -15
cat > /tmp/c360.php <<'P'
<?php
$h='/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php'; $app=require $h.'/cvtara_app/bootstrap/app.php'; $app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $D='Illuminate\Support\Facades\DB';
foreach(['agent_reports'=>"select agent,cadence,period,created_at from agent_reports order by id",'agent_corrections'=>"select agent,metric,period from agent_corrections",'tpl'=>"select status, tested_at is not null t, count(*) n from template_library group by status, tested_at is not null",'errors'=>"select last_seen, occurrences, source, substr(message,1,220) m, substr(location,1,120) l from error_logs where last_seen>'2026-11-12 03:00' and resolved_at is null order by last_seen desc limit 12"] as $k=>$q){try{echo $k.' '.json_encode($D::select($q)).PHP_EOL;}catch(Throwable $e){echo "$k ERR ".substr($e->getMessage(),0,200).PHP_EOL;}}
P
php /tmp/c360.php; rm -f /tmp/c360.php
