#!/bin/bash
# Read-only check after the 3.46.0 deploy: new laravel.log entries since the deploy, and the cron/app health.
H=/home/u280039231/domains/cvtara.com
echo "== $(date -u)"; grep -ho '"version": *"[^"]*"' $H/cvtara_app/BUILD_INFO.json
echo "log entries since 18:49:"; grep -E '^\[2026-11-07 (18:(49|5[0-9])|19:)' $H/cvtara_app/storage/logs/laravel.log | cut -c1-300 | tail -20
echo "count: $(grep -cE '^\[2026-11-07 (18:(49|5[0-9])|19:)' $H/cvtara_app/storage/logs/laravel.log)"
cd $H/cvtara_app && php artisan schedule:list 2>&1 | tail -15
