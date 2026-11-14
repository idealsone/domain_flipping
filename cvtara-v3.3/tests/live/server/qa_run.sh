#!/bin/bash
# Cron entry point: qa_run.sh <setup|logs|cleanup> [since]. Runs each action once (done marker), logs to ~/cvtara_deploy.
D=$HOME/cvtara_deploy/qa; mkdir -p "$D"; cd "$D" || exit 1
A="$1"; M="$D/done_${A}_$3"; [ -f "$M" ] && { cat "$D/last_${A}.log"; exit 0; }
[ -f "$D/lock" ] && exit 0; touch "$D/lock"
/usr/bin/php "$D/qa_${A}.php" "$2" > "$D/last_${A}.log" 2>&1; touch "$M"; rm -f "$D/lock"; cat "$D/last_${A}.log"
