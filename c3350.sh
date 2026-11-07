#!/bin/bash
# Read-only check after 3.35.0: today's log errors and the latest agent runs.
cd /home/u280039231/domains/cvtara.com/cvtara_app
echo "errors today: $(grep -acF '[2026-11-07' storage/logs/laravel.log)"
grep -aF '[2026-11-07' storage/logs/laravel.log | tail -3 | cut -c1-300
php artisan tinker --execute='echo json_encode(["runs"=>DB::table("agent_runs")->orderByDesc("id")->limit(6)->get(), "events"=>DB::table("customer_events")->count(), "errlog"=>DB::table("error_logs")->where("created_at",">=","2026-11-07")->count()]);' 2>&1 | tail -3 | cut -c1-1500
